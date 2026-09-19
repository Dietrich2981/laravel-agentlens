<?php

namespace Agentlens\Contracts;

interface DedupeStore
{
    /**
     * @return bool true when the record may be emitted, false when it must be
     *              suppressed (a later flushSummaries() will account for it).
     */
    public function shouldEmit(string $fingerprint, int $windowSeconds): bool;

    public function incrementAndGetCount(string $fingerprint): int;

    /**
     * Drain accumulated counters.
     *
     * @return array<string, int> [fingerprint => total occurrences]
     *                           `count` includes the already-emitted record.
     */
    public function flushSummaries(): array;
}
