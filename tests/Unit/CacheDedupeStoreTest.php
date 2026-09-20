<?php

use Agentlens\Dedupe\CacheDedupeStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function makeCacheStore(): CacheDedupeStore
{
    return new CacheDedupeStore(new Repository(new ArrayStore));
}

test('cache store suppresses repeats within the window', function () {
    $store = makeCacheStore();

    expect($store->shouldEmit('fp', 60))->toBeTrue();
    expect($store->incrementAndGetCount('fp'))->toBe(1);
    expect($store->shouldEmit('fp', 60))->toBeFalse();
    expect($store->incrementAndGetCount('fp'))->toBe(2);
});

test('a second store instance on the same cache still dedupes (octane workers)', function () {
    $repository = new Repository(new ArrayStore);

    $first = new CacheDedupeStore($repository);
    expect($first->shouldEmit('fp', 60))->toBeTrue();
    $first->incrementAndGetCount('fp');

    // Simulates the next request on the same worker: fresh store object,
    // shared cache backend.
    $second = new CacheDedupeStore($repository);
    expect($second->shouldEmit('fp', 60))->toBeFalse();
    expect($second->incrementAndGetCount('fp'))->toBe(2);

    expect($second->flushSummaries())->toBe([])
        ->and($second->flushOpenWindows(60))->toBe(['fp' => 2]);
});

test('expired windows re-emit', function () {
    $store = makeCacheStore();

    expect($store->shouldEmit('fp', 60))->toBeTrue();
    $store->incrementAndGetCount('fp');

    expect($store->shouldEmit('fp', 0))->toBeTrue();
});

test('expired window totals survive in pending summaries', function () {
    $store = makeCacheStore();

    $store->shouldEmit('fp', 60);
    $store->incrementAndGetCount('fp'); // 1, emitted
    $store->shouldEmit('fp', 60);
    $store->incrementAndGetCount('fp'); // 2, suppressed

    expect($store->shouldEmit('fp', 0))->toBeTrue(); // new window
    $store->incrementAndGetCount('fp');

    expect($store->flushSummaries()['fp'] ?? 0)->toBeGreaterThanOrEqual(2);
});

test('expired-only sweep reports quiet windows and skips hot ones', function () {    $hot = makeCacheStore();
    $hot->shouldEmit('fp', 60);
    $hot->incrementAndGetCount('fp');
    $hot->incrementAndGetCount('fp'); // suppressed, window still open

    expect($hot->flushOpenWindows(60, true))->toBe([]);

    $old = makeCacheStore();
    $old->shouldEmit('fp', 0);
    $old->incrementAndGetCount('fp');
    $old->incrementAndGetCount('fp');

    // Zero-second window: already expired at flush time.
    expect($old->flushOpenWindows(0, true))->toBe(['fp' => 2]);
});

test('meta round-trips through the cache', function () {
    $store = makeCacheStore();

    expect($store->lookupMeta('fp'))->toBeNull();

    $store->noteMeta('fp', 'error', 'burst across instances');

    expect($store->lookupMeta('fp'))->toBe(['level' => 'error', 'message' => 'burst across instances']);
});

test('burst then silence is reported on the next flush after the window', function () {
    // The user's scenario: 50 errors, then quiet, then an unrelated request.
    $repository = new Repository(new ArrayStore);
    $first = new CacheDedupeStore($repository);

    $first->shouldEmit('fp', 60);
    for ($i = 0; $i < 50; $i++) {
        $first->incrementAndGetCount('fp');
    }

    // Window passes with no new sighting (backdate the clock).
    $repository->put('agentlens:dedupe:seen:fp', time() - 120);

    // A later request — even one that logs nothing of its own.
    $second = new CacheDedupeStore($repository);

    expect($second->flushSummaries())->toBe(['fp' => 50])
        // Throttled: an immediate second flush stays silent.
        ->and($second->flushSummaries())->toBe([]);
});
