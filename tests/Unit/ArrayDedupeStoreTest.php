<?php

use Agentlens\Dedupe\ArrayDedupeStore;

test('emits first sighting, suppresses repeats inside the window', function () {
    $store = new ArrayDedupeStore;

    expect($store->shouldEmit('fp', 60))->toBeTrue();
    expect($store->incrementAndGetCount('fp'))->toBe(1);

    expect($store->shouldEmit('fp', 60))->toBeFalse();
    expect($store->incrementAndGetCount('fp'))->toBe(2);
    expect($store->shouldEmit('fp', 60))->toBeFalse();
    expect($store->incrementAndGetCount('fp'))->toBe(3);
});

test('50 identical events give one emission and a final summary of 50', function () {
    $store = new ArrayDedupeStore;
    $emitted = 0;

    for ($i = 0; $i < 50; $i++) {
        if ($store->shouldEmit('fp', 60)) {
            $emitted++;
        }
        $store->incrementAndGetCount('fp');
    }

    // Open window: per-request flush stays silent…
    expect($emitted)->toBe(1)
        ->and($store->flushSummaries())->toBe([])
        // …the total is reported once at true process end.
        ->and($store->flushOpenWindows(60))->toBe(['fp' => 50])
        ->and($store->flushOpenWindows(60))->toBe([]);
});

test('final flush resets open counters so a second final flush is empty', function () {
    $store = new ArrayDedupeStore;

    $store->shouldEmit('fp', 60);
    $store->incrementAndGetCount('fp');
    $store->shouldEmit('fp', 60);
    $store->incrementAndGetCount('fp');

    expect($store->flushOpenWindows(60))->toBe(['fp' => 2])
        ->and($store->flushOpenWindows(60))->toBe([]);
});

test('expired window re-emits and keeps the previous total pending', function () {    $store = new ArrayDedupeStore;

    $store->shouldEmit('fp', 60);
    $store->incrementAndGetCount('fp');
    $store->incrementAndGetCount('fp'); // suppressed repeat, count=2

    // Force window expiry by rewinding the clock via a zero-second window.
    expect($store->shouldEmit('fp', 0))->toBeTrue();
    $store->incrementAndGetCount('fp');

    $summaries = $store->flushSummaries();

    expect($summaries['fp'] ?? 0)->toBeGreaterThanOrEqual(2);
});

test('overflow past 1000 keys prunes expired entries without breaking', function () {
    $store = new ArrayDedupeStore;

    // Fill beyond the prune threshold with zero-second windows (expired).
    for ($i = 0; $i < 1005; $i++) {
        expect($store->shouldEmit("fp-{$i}", 0))->toBeTrue();
        $store->incrementAndGetCount("fp-{$i}");
    }

    // Prune runs on the next new fingerprint: single-occurrence expired
    // entries are evicted and the store keeps working.
    expect($store->shouldEmit('fp-new', 60))->toBeTrue();
    expect($store->flushSummaries())->toBe([]);
});

test('expired-only sweep reports quiet windows and skips hot ones', function () {
    $store = new ArrayDedupeStore;

    $store->shouldEmit('fp', 60);
    $store->incrementAndGetCount('fp');
    $store->incrementAndGetCount('fp'); // suppressed, window still open

    expect($store->flushOpenWindows(60, true))->toBe([])
        ->and($store->flushOpenWindows(60))->toBe(['fp' => 2]);
});

test('clear resets everything', function () {
    $store = new ArrayDedupeStore;

    $store->shouldEmit('fp', 60);
    $store->incrementAndGetCount('fp');
    $store->clear();

    expect($store->shouldEmit('fp', 60))->toBeTrue()
        ->and($store->flushSummaries())->toBe([]);
});
