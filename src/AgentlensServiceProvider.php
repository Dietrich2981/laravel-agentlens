<?php

namespace Agentlens;

use Agentlens\Contracts\AgentDetector;
use Agentlens\Contracts\DedupeStore;
use Agentlens\Contracts\LogEntryFormatter;
use Agentlens\Contracts\MessageNormalizer;
use Agentlens\Dedupe\ArrayDedupeStore;
use Agentlens\Dedupe\CacheDedupeStore;
use Agentlens\Dedupe\FingerprintGenerator;
use Agentlens\Dedupe\RegexMessageNormalizer;
use Agentlens\Detection\EnvAndTtyAgentDetector;
use Agentlens\Exceptions\AgentlensExceptionReporter;
use Agentlens\Formatting\CompactJsonFormatter;
use Agentlens\Formatting\TraceTrimmer;
use Agentlens\Logging\AgentlensHandler;
use Agentlens\Sql\LastQueryBuffer;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;

class AgentlensServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agentlens.php', 'agentlens');

        $this->app->singleton(AgentDetector::class, EnvAndTtyAgentDetector::class);
        $this->app->singleton(MessageNormalizer::class, RegexMessageNormalizer::class);

        $this->app->singleton(FingerprintGenerator::class, fn ($app) => new FingerprintGenerator(
            $app->make(MessageNormalizer::class)
        ));

        $this->app->singleton(TraceTrimmer::class, fn () => new TraceTrimmer(
            maxFrames: max(1, (int) config('agentlens.trace.max_frames', 3)),
            skipVendorFrames: (bool) config('agentlens.trace.skip_vendor_frames', true),
            basePath: function_exists('base_path') ? base_path() : '',
        ));

        $this->app->singleton(LogEntryFormatter::class, fn ($app) => new CompactJsonFormatter(
            $app->make(TraceTrimmer::class)
        ));

        $this->app->singleton(LastQueryBuffer::class);

        $this->app->singleton(DedupeStore::class, function ($app) {
            if (config('agentlens.dedupe.store') === 'cache') {
                return new CacheDedupeStore($app['cache']->store());
            }

            return new ArrayDedupeStore;
        });

        $this->app->singleton(AgentlensHandler::class, fn ($app) => new AgentlensHandler(
            $app->make(FingerprintGenerator::class),
            $app->make(LogEntryFormatter::class),
            $app->make(DedupeStore::class),
            $app->make(LastQueryBuffer::class),
        ));

        $this->app->singleton(AgentlensExceptionReporter::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/agentlens.php' => config_path('agentlens.php'),
        ], 'agentlens-config');

        // THE zero-overhead gate: one cached verdict per lifecycle. When the
        // verdict is negative, nothing below is registered — not disabled by
        // a flag, physically absent.
        if (! $this->isAgentMode()) {
            return;
        }

        $this->registerLogChannel();
        $this->mixChannelIntoStack();
        $this->registerExceptionHook();
        $this->registerLastQueryBuffer();
        $this->registerSummaryFlush();
        $this->announceToStderr();
    }

    /** One-time STDERR pointer so the agent discovers the mirror file. */
    protected static bool $announced = false;

    protected function announceToStderr(): void
    {
        if (! $this->shouldAnnounce()) {
            return;
        }

        static::$announced = true;

        try {
            $path = $this->app->make(AgentlensHandler::class)->destinationPath();
            @fwrite(STDERR, $this->buildStderrPointer($path).PHP_EOL);
        } catch (\Throwable) {
            // Discovery must never break boot.
        }
    }

    protected function shouldAnnounce(): bool
    {
        if (static::$announced) {
            return false;
        }

        try {
            // Reuses force-mode normalization so AGENTLENS_DISCOVERY_STDERR=false
            // (a "false" string from env) really disables the pointer.
            if ($this->normalizeForced(config('agentlens.discovery.stderr', true)) === false) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        // STDERR keeps machine-readable STDOUT (e.g. `artisan --json`) clean.
        if (! in_array(PHP_SAPI, ['cli', 'phpdbg', 'cli-server'], true)) {
            return false;
        }

        try {
            if ($this->app->runningUnitTests()) {
                return false;
            }
        } catch (\Throwable) {
            // ignore — announce anyway
        }

        return true;
    }

    protected function buildStderrPointer(string $path): string
    {
        return "[agentlens] compact deduped mirror for agents: {$path} (human log unchanged)";
    }

    /**
     * Master switch AND agent verdict. `force_agent_mode` fully bypasses the
     * detector (acceptance requirement); string env values are normalized so
     * AGENTLENS_FORCE=false really means false.
     */
    public function isAgentMode(): bool
    {
        try {
            if (! config('agentlens.enabled', true)) {
                return false;
            }

            $forced = $this->normalizeForced(config('agentlens.force_agent_mode'));
            if ($forced !== null) {
                return $forced;
            }

            return $this->app->make(AgentDetector::class)->isAgentContext();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function normalizeForced(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === '') {
                return null;
            }
            if (in_array($v, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'n', 'off'], true)) {
                return false;
            }

            return null;
        }

        return (bool) $value;
    }

    protected function registerLogChannel(): void
    {
        $this->ensureChannelConfig();

        try {
            Log::extend('agentlens', fn ($app) => new Logger('agentlens', [
                $app->make(AgentlensHandler::class),
            ]));
        } catch (\Throwable) {
            // LogManager unavailable — exception hook still works via reporter guard.
        }
    }

    protected function ensureChannelConfig(): void
    {
        if (config('logging.channels.agentlens') !== null) {
            return;
        }

        config(['logging.channels.agentlens' => [
            'driver' => 'agentlens',
            'level' => config('agentlens.logging.level', 'debug'),
            'path' => config('agentlens.logging.path'),
        ]]);
    }

    /**
     * Zero-config: append `agentlens` to the active stack when the default
     * channel is a stack. Non-stack defaults are left untouched — the channel
     * stays available explicitly and the exception hook uses it directly.
     */
    protected function mixChannelIntoStack(): void
    {
        $default = config('logging.default');
        if (! is_string($default) || $default === '') {
            return;
        }

        $channels = config("logging.channels.{$default}.channels");
        if (! is_array($channels)) {
            return;
        }

        if (! in_array(config('agentlens.logging.channel', 'agentlens'), $channels, true)) {
            $channels[] = config('agentlens.logging.channel', 'agentlens');
            config(["logging.channels.{$default}.channels" => $channels]);
        }
    }

    /**
     * Hook unhandled exceptions WITHOUT touching bootstrap/app.php: the
     * reportable callback runs alongside the normal report flow on
     * Laravel 11/12/13 (Illuminate\Foundation\Exceptions\Handler).
     */
    protected function registerExceptionHook(): void
    {
        if (! config('agentlens.exceptions.capture_unhandled', true)) {
            return;
        }

        try {
            $handler = $this->app->make(ExceptionHandler::class);
        } catch (\Throwable) {
            return;
        }

        if (! method_exists($handler, 'reportable')) {
            return;
        }

        try {
            $handler->reportable(function (\Throwable $e) {
                if (! $this->isAgentMode()) {
                    return;
                }

                if ($this->stackAlreadyCaptures()) {
                    return;
                }

                $this->app->make(AgentlensExceptionReporter::class)->report($e);
            });
        } catch (\Throwable) {
            // Handler contract changed under us — human reporting untouched.
        }
    }

    /**
     * True when default reporting already delivers the record to our channel
     * (default IS `agentlens`, or a stack containing it). Checked at report
     * time against runtime config, so the hook never double-writes. On any
     * doubt returns false — a duplicate write is harmless (dedupe absorbs
     * it), a missed exception is not. Direct reporter calls always write.
     */
    protected function stackAlreadyCaptures(): bool
    {
        try {
            if (! function_exists('config')) {
                return false;
            }

            $channel = config('agentlens.logging.channel', 'agentlens');
            if (! is_string($channel) || $channel === '') {
                $channel = 'agentlens';
            }

            $default = config('logging.default');
            if (! is_string($default) || $default === '') {
                return false;
            }

            if ($default === $channel) {
                return true;
            }

            $channels = config("logging.channels.{$default}.channels");

            return is_array($channels) && in_array($channel, $channels, true);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function registerLastQueryBuffer(): void
    {
        if (! config('agentlens.sql.enabled', true)) {
            return;
        }

        try {
            DB::listen(function (QueryExecuted $query) {
                $this->app->make(LastQueryBuffer::class)->record($query->sql, $query->bindings, $query->time);
            });
        } catch (\Throwable) {
            // No database configured — nothing to buffer.
        }
    }

    /**
     * Two-tier summary flush:
     * - terminating / RequestTerminated: completed windows only — safe to run
     *   per request (serve, Octane), never spams per-request summaries;
     * - PHP shutdown: final drain including in-progress windows — runs once
     *   at true process end (command, FPM request, serve Ctrl+C, worker end).
     */
    protected function registerSummaryFlush(): void
    {
        $flush = function () {
            try {
                $this->app->make(AgentlensHandler::class)->flushSummaries();
            } catch (\Throwable) {
                // Never break termination.
            }
        };

        $flushFinal = function () {
            try {
                $this->app->make(AgentlensHandler::class)->flushFinal();
            } catch (\Throwable) {
                // Never break shutdown.
            }
        };

        try {
            $this->app->terminating($flush);
        } catch (\Throwable) {
            // ignore
        }

        try {
            register_shutdown_function($flushFinal);
        } catch (\Throwable) {
            // ignore
        }

        try {
            if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
                $this->app->make('events')->listen(
                    \Laravel\Octane\Events\RequestTerminated::class,
                    $flush
                );
            }
        } catch (\Throwable) {
            // Octane not installed or events unavailable.
        }
    }
}
