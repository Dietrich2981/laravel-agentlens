<?php

namespace Agentlens\Contracts;

/**
 * Optional DedupeStore capability: remember which record a fingerprint came
 * from, so window summaries can repeat the original message even when they
 * are flushed by a different process/request that never saw the record
 * (serve burst → silence → unrelated request).
 */
interface RemembersMeta
{
    public function noteMeta(string $fingerprint, string $level, string $message): void;

    /** @return array{level: string, message: string}|null */
    public function lookupMeta(string $fingerprint): ?array;
}
