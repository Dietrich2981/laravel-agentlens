<?php

namespace Agentlens\Dedupe;

use Agentlens\Contracts\DedupeStore;
use Agentlens\Contracts\FlushesOpenWindows;

/**
 * In-memory store. Ideal for classic PHP-FPM/CLI lifecycles (dedupes repeats
 * inside one request/command, e.g. a retry loop). No I/O, no network.
 *
 * Semantics (also documented in README "Deduplication" section):
 * - First sighting inside a window emits the full record with count=1.
 * - Repeats are suppressed and counted.
 * - flushSummaries() drains completed windows only (safe per request).
 * - When a window expires, the previous total is stashed as pending so the
 *   next flush still reports it even though a new window has started.
 * - flushOpenWindows() additionally drains in-progress windows; the handler
 *   calls it once at true process end (PHP shutdown).
 *
 * Long-running (Octane) workers: entries are pruned opportunistically
 * (expired entries dropped once the map exceeds 1000 keys), but prefer the
 * `cache` store there — see CacheDedupeStore.
 */
class ArrayDedupeStore implements DedupeStore, FlushesOpenWindows
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

    /**
     * Drain completed windows only (expired, moved to pending by shouldEmit).
     * Safe to call per request: open windows are left untouched.
     */
    public function flushSummaries(): array
    {
        $out = $this->pending;
        $this->pending = [];

        return $out;
    }

    /**
     * Drain in-progress windows too. Called once at true process end.
     */
    public function flushOpenWindows(int $windowSeconds, bool $onlyExpired = false): array
    {
        $out = [];
        $now = time();

        foreach ($this->entries as $fingerprint => $entry) {
            if ($entry['count'] <= 1) {
                continue;
            }

            if ($onlyExpired && ($now - $entry['seen']) < $windowSeconds) {
                continue;
            }

            $out[$fingerprint] = $entry['count'];
            $this->entries[$fingerprint]['count'] = 1;
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
