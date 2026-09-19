<?php

use Illuminate\Support\Facades\Log;

test('zero config creates the channel and mixes it into the stack', function () {
    expect(config('logging.channels.agentlens.driver'))->toBe('agentlens')
        ->and(config('logging.channels.stack.channels'))->toContain('agentlens');
});

test('zero config uses documented defaults', function () {
    expect(config('agentlens.enabled'))->toBeTrue()
        ->and(config('agentlens.dedupe.enabled'))->toBeTrue()
        ->and(config('agentlens.dedupe.window_seconds'))->toBe(10)
        ->and(config('agentlens.dedupe.store'))->toBe('array')
        ->and(config('agentlens.trace.max_frames'))->toBe(3)
        ->and(config('agentlens.trace.skip_vendor_frames'))->toBeTrue()
        ->and(config('agentlens.sql.include_failed_query'))->toBeTrue()
        ->and(config('agentlens.sql.max_query_length'))->toBe(500)
        ->and(config('agentlens.exceptions.capture_unhandled'))->toBeTrue()
        ->and(config('agentlens.discovery.header'))->toBeTrue()
        ->and(config('agentlens.discovery.stderr'))->toBeTrue();
});

test('zero config writes compact json to the default storage path', function () {
    Log::channel('stack')->error('zero config boom');

    expect(file_exists($this->agentlensLogPath))->toBeTrue();

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true))->toMatchArray(['lvl' => 'error', 'msg' => 'zero config boom']);
});
