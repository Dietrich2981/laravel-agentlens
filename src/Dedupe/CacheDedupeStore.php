<?php

namespace Agentlens\Dedupe;

use Agentlens\Contracts\DedupeStore;
use Illuminate\Contracts\Cache\Repository;

/**
 * Shared-cache store for Octane / long-running workers: two requests handled
 * by the same worker (or even different workers on a shared cache) dedupe
 * against each other inside `window_seconds`.
 *
 * Same drain semantics as ArrayDedupeStore; see that class for details.
 */
class CacheDedupeStore implements DedupeStore
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

    public function flushSummaries(): array
    {
        $out = (array) $this->cache->get($this->pendingKey(), []);
        $this->cache->put($this->pendingKey(), [], $this->keyTtlSeconds);

        foreach ($this->trackedKeys() as $fingerprint) {
            $count = (int) $this->cache->get($this->countKey($fingerprint), 0);
            if ($count > 1) {
                $out[$fingerprint] = ($out[$fingerprint] ?? 0) + $count;
                $this->cache->put($this->countKey($fingerprint), 1, $this->keyTtlSeconds);
            }
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
