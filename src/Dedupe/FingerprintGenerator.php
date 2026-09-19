<?php

namespace Agentlens\Dedupe;

use Agentlens\Contracts\MessageNormalizer;
use Agentlens\Formatting\LogRecordDTO;

/**
 * Fingerprint = hash(exception class : file : line) for throwables,
 * or hash(level : normalized message) for plain log records.
 */
class FingerprintGenerator
{
    public function __construct(
        protected MessageNormalizer $normalizer,
    ) {}

    public function forRecord(LogRecordDTO $record): string
    {
        if ($record->exceptionClass !== null) {
            return sha1($record->exceptionClass.':'.$record->file.':'.$record->line);
        }

        return sha1($record->level.':'.$this->normalizer->normalize($record->message));
    }

    public function forThrowable(\Throwable $e): string
    {
        return sha1(get_class($e).':'.$e->getFile().':'.$e->getLine());
    }
}
