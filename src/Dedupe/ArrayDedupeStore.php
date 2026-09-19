<?php

namespace Agentlens\Dedupe;

use Agentlens\Contracts\DedupeStore;

/**
 * In-memory store. Ideal for classic PHP-FPM/CLI lifecycles (dedupes repeats
 * inside one request/command, e.g. a retry loop). No I/O, no network.
 *
 * Semantics (also documented in README "Deduplication" section):
 * - First sighting inside a window emits the full record with count=1.
 * - Repeats are suppressed and counted.
 * - flushSummaries() drains every fingerprint with count > 1 as
 *   [fingerprint => total occurrences] and resets its counter to 1, so a
 *   later flush in the same window only reports *new* occurrences.
 * - When a window expires, the previous total is stashed as pending so the
 *   next flush still reports it even though a new window has started.
 *
 * Long-running (Octane) workers: entries are pruned opportunistically
 * (expired entries dropped once the map exceeds 1000 keys), but prefer the
 * `cache` store there — see CacheDedupeStore.
 */
class ArrayDedupeStore implements DedupeStore
{
    /** @var array<string, array{seen: int, count: int}> */
    protected array $entries = [];

    /** @var array<string, int> expired-window totals awaiting flush */
    protected array $pending = [];

    public function shouldEmit(string $fingerprint, int $windowSeconds): bool
    {
        $now = time();

        if (! isset($this->entries[$fingerprint])) {
            $this->entries[$fingerprint] = ['seen' => $now, 'count' => 0];
            $this->pruneIfNeeded($now, $windowSeconds);

            return true;
        }

        $entry = $this->entries[$fingerprint];

        if (($now - $entry['seen']) >= $windowSeconds) {
            if ($entry['count'] > 1) {
                $this->pending[$fingerprint] = ($this->pending[$fingerprint] ?? 0) + $entry['count'];
            }
            $this->entries[$fingerprint] = ['seen' => $now, 'count' => 0];
            $this->pruneIfNeeded($now, $windowSeconds);

            return true;
        }

        return false;
    }

    public function incrementAndGetCount(string $fingerprint): int
    {
        if (! isset($this->entries[$fingerprint])) {
            $this->entries[$fingerprint] = ['seen' => time(), 'count' => 0];
        }

        return ++$this->entries[$fingerprint]['count'];
    }

    public function flushSummaries(): array
    {
        $out = $this->pending;
        $this->pending = [];

        foreach ($this->entries as $fingerprint => $entry) {
            if ($entry['count'] > 1) {
                $out[$fingerprint] = ($out[$fingerprint] ?? 0) + $entry['count'];
                $this->entries[$fingerprint]['count'] = 1;
            }
        }

        return $out;
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->pending = [];
    }

    protected function pruneIfNeeded(int $now, int $windowSeconds): void
    {
        if (count($this->entries) <= 1000) {
            return;
        }

        foreach ($this->entries as $fingerprint => $entry) {
            if (($now - $entry['seen']) >= $windowSeconds && $entry['count'] <= 1) {
                unset($this->entries[$fingerprint]);
            }
        }
    }
}
