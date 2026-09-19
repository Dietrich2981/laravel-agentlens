<?php

namespace Agentlens\Formatting;

use Agentlens\Sql\LastQueryBuffer;
use Agentlens\Sql\SqlExtractor;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Framework-agnostic value object: everything the compact formatter needs.
 */
final class LogRecordDTO
{
    public function __construct(
        public string $level,
        public string $message,
        public ?string $exceptionClass = null,
        public ?string $file = null,
        public ?int $line = null,
        /** @var array<int, array> raw Throwable::getTrace() frames */
        public array $trace = [],
        /** @var array<string, mixed> explicit context only (no request dump) */
        public array $context = [],
        public ?string $sql = null,
        public int $count = 1,
    ) {}

    public static function fromMonologRecord(
        LogRecord $record,
        ?LastQueryBuffer $buffer = null,
        int $maxSqlLength = 500,
    ): self {
        $level = $record->level instanceof Level
            ? strtolower($record->level->name)
            : strtolower((string) $record->level);

        $context = $record->context;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        if ($exception instanceof \Throwable) {
            return self::fromThrowable($exception, $level, $context, $buffer, $maxSqlLength);
        }

        return new self(level: $level, message: $record->message, context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromThrowable(
        \Throwable $e,
        string $level = 'error',
        array $context = [],
        ?LastQueryBuffer $buffer = null,
        int $maxSqlLength = 500,
    ): self {
        $previous = $e->getPrevious();
        $message = $e->getMessage() !== ''
            ? $e->getMessage()
            : ($previous ? get_class($previous).': '.$previous->getMessage() : get_class($e));

        return new self(
            level: $level,
            message: $message,
            exceptionClass: get_class($e),
            file: $e->getFile(),
            line: $e->getLine(),
            trace: $e->getTrace(),
            context: $context,
            sql: SqlExtractor::extract($e, $buffer, $maxSqlLength),
        );
    }
}
