<?php

namespace Agentlens\Dedupe;

use Agentlens\Contracts\DedupeStore;
use Agentlens\Contracts\FlushesOpenWindows;
use Agentlens\Contracts\RemembersMeta;
use Illuminate\Contracts\Cache\Repository;

/**
 * Shared-cache store for Octane / long-running workers: two requests handled
 * by the same worker (or even different workers on a shared cache) dedupe
 * against each other inside `window_seconds`.
 *
 * Same drain semantics as ArrayDedupeStore; see that class for details.
 * flushSummaries() additionally sweeps expired open windows, throttled to at
 * most one sweep per window via a shared timestamp — so a burst followed by
 * silence is reported as soon as any later request ends, at the cost of one
 * extra cache read per flush. Never spams: hot windows are left untouched.
 */
class CacheDedupeStore implements DedupeStore, FlushesOpenWindows, RemembersMeta
{
    protected const KEY_PREFIX = 'agentlens:dedupe:';

    public function __construct(
        protected Repository $cache,
        protected int $keyTtlSeconds = 3600,
    ) {}

    public function shouldEmit(string $fingerprint, int $windowSeconds): bool
    {
        $now = time();
        $seen = $this->cache->get($this->seenKey($fingerprint));

        if ($seen === null || (($now - (int) $seen) >= $windowSeconds)) {
            $previous = (int) $this->cache->get($this->countKey($fingerprint), 0);
            if ($previous > 1) {
                $pending = (array) $this->cache->get($this->pendingKey(), []);
                $pending[$fingerprint] = ($pending[$fingerprint] ?? 0) + $previous;
                $this->cache->put($this->pendingKey(), $pending, $this->keyTtlSeconds);
            }

            $this->cache->put($this->seenKey($fingerprint), $now, $this->keyTtlSeconds);
            $this->cache->put($this->countKey($fingerprint), 0, $this->keyTtlSeconds);
            $this->rememberWindow($windowSeconds);
            $this->trackKey($fingerprint);

            return true;
        }

        return false;
    }

    public function incrementAndGetCount(string $fingerprint): int
    {
        $key = $this->countKey($fingerprint);

        if ($this->cache->get($key) === null) {
            $this->cache->put($key, 0, $this->keyTtlSeconds);
            $this->trackKey($fingerprint);
        }

        $value = $this->cache->increment($key);

        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * Drain completed windows plus a throttled sweep of expired open windows.
     * Safe to call per request: hot windows are left untouched, and the sweep
     * runs at most once per window (shared timestamp), so the steady-state
     * cost is a single extra cache read.
     */
    public function flushSummaries(): array
    {
        $out = (array) $this->cache->get($this->pendingKey(), []);
        $this->cache->put($this->pendingKey(), [], $this->keyTtlSeconds);

        try {
            foreach ($this->sweepExpired() as $fingerprint => $count) {
                $out[$fingerprint] = ($out[$fingerprint] ?? 0) + $count;
            }
        } catch (\Throwable) {
            // Sweep is best-effort (odd cache drivers); pending drain above
            // already happened and is never lost.
        }

        return $out;
    }

    /**
     * Report open windows expired as of now; at most one sweep per window
     * across all processes sharing this cache.
     *
     * @return array<string, int>
     */
    protected function sweepExpired(): array
    {
        $now = time();
        $window = $this->knownWindow();
        $lastSweep = (int) $this->cache->get($this->sweepKey(), 0);

        if (($now - $lastSweep) < $window) {
            return [];
        }

        $this->cache->put($this->sweepKey(), $now, $this->keyTtlSeconds);

        $out = [];

        foreach ($this->trackedKeys() as $fingerprint) {
            $seen = $this->cache->get($this->seenKey($fingerprint));
            if ($seen === null || (($now - (int) $seen) < $window)) {
                continue;
            }

            $count = (int) $this->cache->get($this->countKey($fingerprint), 0);
            if ($count > 1) {
                $out[$fingerprint] = $count;
                $this->cache->put($this->countKey($fingerprint), 1, $this->keyTtlSeconds);
            }
        }

        return $out;
    }

    protected function rememberWindow(int $windowSeconds): void
    {
        try {
            if ((int) $this->cache->get($this->windowKey(), 0) !== $windowSeconds) {
                $this->cache->put($this->windowKey(), $windowSeconds, $this->keyTtlSeconds);
            }
        } catch (\Throwable) {
            // ignore — sweep falls back to the default window
        }
    }

    protected function knownWindow(): int
    {
        try {
            return max(1, (int) $this->cache->get($this->windowKey(), 10));
        } catch (\Throwable) {
            return 10;
        }
    }

    /**
     * Drain in-progress windows too. Called once at true process end.
     */
    public function flushOpenWindows(int $windowSeconds, bool $onlyExpired = false): array
    {
        $out = [];
        $now = time();

        foreach ($this->trackedKeys() as $fingerprint) {
            $count = (int) $this->cache->get($this->countKey($fingerprint), 0);
            if ($count <= 1) {
                continue;
            }

            if ($onlyExpired) {
                $seen = $this->cache->get($this->seenKey($fingerprint));
                if ($seen === null || (($now - (int) $seen) < $windowSeconds)) {
                    continue;
                }
            }

            $out[$fingerprint] = $count;
            $this->cache->put($this->countKey($fingerprint), 1, $this->keyTtlSeconds);
        }

        return $out;
    }

    protected function countKey(string $fingerprint): string
    {
        return self::KEY_PREFIX.'count:'.$fingerprint;
    }

    protected function seenKey(string $fingerprint): string
    {
        return self::KEY_PREFIX.'seen:'.$fingerprint;
    }

    protected function pendingKey(): string
    {
        return self::KEY_PREFIX.'pending';
    }

    protected function sweepKey(): string
    {
        return self::KEY_PREFIX.'lastsweep';
    }

    public function noteMeta(string $fingerprint, string $level, string $message): void
    {
        try {
            $this->cache->put($this->metaKey($fingerprint), ['level' => $level, 'message' => $message], $this->keyTtlSeconds);
        } catch (\Throwable) {
            // Meta is best-effort; counts are the source of truth.
        }
    }

    public function lookupMeta(string $fingerprint): ?array
    {
        try {
            $meta = $this->cache->get($this->metaKey($fingerprint));

            if (is_array($meta) && isset($meta['level'], $meta['message'])) {
                return ['level' => (string) $meta['level'], 'message' => (string) $meta['message']];
            }
        } catch (\Throwable) {
            // ignore — caller falls back
        }

        return null;
    }

    protected function metaKey(string $fingerprint): string
    {
        return self::KEY_PREFIX.'meta:'.$fingerprint;
    }

    protected function windowKey(): string
    {
        return self::KEY_PREFIX.'window';
    }

    protected function indexKey(): string
    {
        return self::KEY_PREFIX.'keys';
    }

    protected function trackKey(string $fingerprint): void
    {
        $keys = (array) $this->cache->get($this->indexKey(), []);
        if (! in_array($fingerprint, $keys, true)) {
            $keys[] = $fingerprint;
            $this->cache->put($this->indexKey(), array_slice($keys, -2000), $this->keyTtlSeconds);
        }
    }

    /**
     * @return string[]
     */
    protected function trackedKeys(): array
    {
        return array_values(array_filter(
            (array) $this->cache->get($this->indexKey(), []),
            'is_string'
        ));
    }
}
