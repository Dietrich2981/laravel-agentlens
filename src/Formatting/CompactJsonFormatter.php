<?php

namespace Agentlens\Formatting;

use Agentlens\Contracts\LogEntryFormatter;

/**
 * One compact JSON object per line. Short keys save tokens; the human never
 * sees this format. No ANSI codes, no box-drawing, no emoji — ever.
 */
class CompactJsonFormatter implements LogEntryFormatter
{
    public function __construct(
        protected TraceTrimmer $trimmer,
    ) {}

    public function format(LogRecordDTO $record): string
    {
        $entry = [
            'lvl' => $record->level,
            'msg' => $this->truncate($record->message, 1000),
        ];

        if ($record->file !== null) {
            $entry['at'] = $record->file.':'.$record->line;
        }

        if ($record->context !== []) {
            $entry['ctx'] = $record->context;
        }

        if ($record->sql !== null && $record->sql !== '') {
            $entry['sql'] = $record->sql;
        }

        $entry['count'] = $record->count;

        if ($record->trace !== []) {
            $entry['trace_top'] = $this->trimmer->trim($record->trace);
        }

        return $this->encode($entry);
    }

    /** Final summary line after a dedupe window drains. */
    public function formatSummary(string $level, string $message, int $count, int $windowSeconds): string
    {
        return $this->encode([
            'lvl' => $level,
            'msg' => $this->truncate($message, 1000),
            'count' => $count,
            'window_s' => $windowSeconds,
        ]);
    }

    protected function encode(array $entry): string
    {
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            // Malformed UTF-8 in message/context must never break logging.
            $json = json_encode(
                $this->sanitize($entry),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
        }

        return $json === false ? '{"lvl":"error","msg":"[unencodable log entry]","count":1}' : $json;
    }

    protected function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[is_string($k) ? mb_convert_encoding($k, 'UTF-8', 'UTF-8') : $k] = $this->sanitize($v);
            }

            return $out;
        }

        return $value;
    }

    protected function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }
}
