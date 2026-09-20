<?php

namespace Agentlens\Logging;

use Agentlens\Contracts\LogEntryFormatter;
use Agentlens\Dedupe\FingerprintGenerator;
use Agentlens\Formatting\CompactJsonFormatter;
use Agentlens\Formatting\LogRecordDTO;
use Agentlens\Sql\LastQueryBuffer;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler behind the `agentlens` log channel.
 *
 * Pipeline per record: build DTO → fingerprint → dedupe gate → (emit full
 * JSON line | suppress + count). Drained windows are flushed as one summary
 * line each via flushSummaries() (called on app termination).
 *
 * The destination path is resolved lazily at each write from
 * `logging.channels.agentlens.path` (fallback: storage/logs/agentlens.log),
 * so runtime config changes (tests, Octane config reloads) just work.
 *
 * @phpstan-type FingerprintMeta array{level: string, message: string}
 */
class AgentlensHandler extends AbstractProcessingHandler
{
    /** @var resource|null */
    protected $stream = null;

    protected ?string $openedForPath = null;

    /** @var array<string, array{level: string, message: string}> */
    protected array $meta = [];

    protected int $windowSeconds = 10;

    public function __construct(
        protected FingerprintGenerator $fingerprints,
        protected LogEntryFormatter $entryFormatter,
        protected \Agentlens\Contracts\DedupeStore $store,
        protected ?LastQueryBuffer $buffer = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        if (! $this->dedupeEnabled()) {
            $this->writeLine($this->entryFormatter->format(
                LogRecordDTO::fromMonologRecord($record, $this->buffer, $this->maxSqlLength())
            ));

            return false === $this->bubble;
        }

        $dto = LogRecordDTO::fromMonologRecord($record, $this->buffer, $this->maxSqlLength());
        $fingerprint = $this->fingerprints->forRecord($dto);

        $this->meta[$fingerprint] = ['level' => $dto->level, 'message' => $dto->message];

        if (! $this->store->shouldEmit($fingerprint, $this->windowSeconds())) {
            $this->store->incrementAndGetCount($fingerprint);

            return false === $this->bubble;
        }

        // A new window just opened (or first sighting): report the drained
        // previous window(s) first, then the fresh full record.
        $this->writePendingSummaries();

        $dto->count = $this->store->incrementAndGetCount($fingerprint);

        if ($this->store instanceof \Agentlens\Contracts\RemembersMeta) {
            $this->store->noteMeta($fingerprint, $dto->level, $dto->message);
        }

        $this->writeLine($this->entryFormatter->format($dto));

        return false === $this->bubble;
    }

    /**
     * Write one summary line per drained completed window. Safe to call any
     * time (e.g. per request); emits nothing when there is nothing to report.
     */
    public function flushSummaries(): void
    {
        $this->writePendingSummaries();
    }

    /**
     * Final drain at process end (PHP shutdown): completed windows plus
     * in-progress ones. Writes nothing when the handler never emitted.
     *
     * On web SAPIs (serve, FPM, …) shutdown fires per request while the
     * process lives on, so only already-expired windows are reported —
     * recurring errors still surface via rollover on the next request.
     */
    public function flushFinal(): void
    {
        $this->writePendingSummaries();

        if (! $this->store instanceof \Agentlens\Contracts\FlushesOpenWindows) {
            return;
        }

        $onlyExpired = ! in_array(PHP_SAPI, ['cli', 'phpdbg'], true);

        foreach ($this->store->flushOpenWindows($this->windowSeconds(), $onlyExpired) as $fingerprint => $count) {
            $this->writeSummaryLine($fingerprint, (int) $count);
        }
    }

    protected function writePendingSummaries(): void
    {
        foreach ($this->store->flushSummaries() as $fingerprint => $count) {
            $this->writeSummaryLine($fingerprint, (int) $count);
        }
    }

    protected function writeSummaryLine(string $fingerprint, int $count): void
    {
        // Own sightings first, then the store (a sweep may run in a process
        // that never saw the record — serve burst, silence, other request).
        $meta = $this->meta[$fingerprint]
            ?? ($this->store instanceof \Agentlens\Contracts\RemembersMeta
                ? $this->store->lookupMeta($fingerprint)
                : null)
            ?? ['level' => 'error', 'message' => '[repeated log]'];

        if ($this->entryFormatter instanceof CompactJsonFormatter) {
            $line = $this->entryFormatter->formatSummary($meta['level'], $meta['message'], $count, $this->windowSeconds());
        } else {
            $line = $this->entryFormatter->format(new LogRecordDTO(
                level: $meta['level'],
                message: $meta['message'].' (repeated '.$count.'x)',
                count: $count,
            ));
        }

        $this->writeLine($line);
    }

    protected function writeLine(string $line): void
    {
        $path = $this->resolvePath();
        $stream = $this->streamFor($path);

        if ($stream === null) {
            return;
        }

        flock($stream, LOCK_EX);
        try {
            // Fresh file (checked under the exclusive lock, so concurrent
            // workers cannot double-write it): explain the format first.
            if ($this->headerEnabled() && $this->isFreshStream($stream)) {
                fwrite($stream, $this->headerLine()."\n");
            }
            fwrite($stream, $line."\n");
        } finally {
            flock($stream, LOCK_UN);
        }
    }

    /** Schema legend: first line of every fresh log file (valid JSON). */
    protected function headerLine(): string
    {
        return json_encode([
            'agentlens' => '1',
            'hint' => 'compact deduped runtime log for AI agents: lvl=level msg=message at=file:line ctx=explicit context only sql=failed SQL count=occurrences in window trace_top=top stack frames, vendor frames collapsed into (vendor skipped: N frames); lines with window_s are window summaries; the human-readable log is unchanged elsewhere',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param resource $stream
     */
    protected function isFreshStream($stream): bool
    {
        $stat = @fstat($stream);

        return is_array($stat) && ($stat['size'] ?? 0) === 0;
    }

    protected function headerEnabled(): bool
    {
        try {
            return function_exists('config') ? (bool) config('agentlens.discovery.header', true) : true;
        } catch (\Throwable) {
            return true;
        }
    }

    /** Resolved destination (config path or package default). Public for the boot announcement. */
    public function destinationPath(): string
    {
        return $this->resolvePath();
    }

    /**
     * @return resource|null
     */
    protected function streamFor(string $path)
    {
        if ($this->stream !== null && $this->openedForPath === $path) {
            return $this->stream;
        }

        $this->close();

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $stream = @fopen($path, 'a');
        if ($stream === false) {
            return null;
        }

        $this->stream = $stream;
        $this->openedForPath = $path;

        return $this->stream;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
        $this->openedForPath = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    protected function resolvePath(): string
    {
        try {
            if (function_exists('config')) {
                $configured = config('logging.channels.agentlens.path');
                if (is_string($configured) && $configured !== '') {
                    return $configured;
                }
            }
        } catch (\Throwable) {
            // fall through to default
        }

        try {
            if (function_exists('storage_path')) {
                return storage_path('logs/agentlens.log');
            }
        } catch (\Throwable) {
            // fall through
        }

        return sys_get_temp_dir().'/agentlens.log';
    }

    protected function windowSeconds(): int
    {
        if ($this->windowSeconds <= 0) {
            try {
                $this->windowSeconds = function_exists('config')
                    ? max(1, (int) config('agentlens.dedupe.window_seconds', 10))
                    : 10;
            } catch (\Throwable) {
                $this->windowSeconds = 10;
            }
        }

        return $this->windowSeconds;
    }

    protected function dedupeEnabled(): bool
    {
        try {
            return function_exists('config') ? (bool) config('agentlens.dedupe.enabled', true) : true;
        } catch (\Throwable) {
            return true;
        }
    }

    protected function maxSqlLength(): int
    {
        try {
            return function_exists('config') ? max(50, (int) config('agentlens.sql.max_query_length', 500)) : 500;
        } catch (\Throwable) {
            return 500;
        }
    }

    /** Test seam: forget lazily-resolved state between reconfigurations. */
    public function resetForTests(): void
    {
        $this->close();
        $this->windowSeconds = 0;
        $this->meta = [];
    }

    /** Required by AbstractProcessingHandler; unused (we override handle()). */
    protected function write(LogRecord $record): void
    {
        // no-op — emission goes through handle() -> writeLine().
    }
}
