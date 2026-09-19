<?php

use Agentlens\Exceptions\AgentlensExceptionReporter;

function throwDeep(int $depth): void
{
    if ($depth <= 0) {
        throw new RuntimeException('deep boom');
    }
    throwDeep($depth - 1);
}

test('a 30-frame exception is trimmed to max frames plus vendor marker', function () {
    try {
        throwDeep(30);
    } catch (RuntimeException $e) {
        expect(count($e->getTrace()))->toBeGreaterThanOrEqual(30);

        $this->app->make(AgentlensExceptionReporter::class)->report($e);
    }

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(1);

    $decoded = json_decode($lines[0], true);
    $maxFrames = config('agentlens.trace.max_frames');

    expect($decoded)->toHaveKey('trace_top')
        ->and(count($decoded['trace_top']))->toBeLessThanOrEqual($maxFrames + 1);

    $frames = array_filter(
        $decoded['trace_top'],
        fn ($f) => ! str_contains($f, 'vendor skipped')
    );

    foreach ($frames as $frame) {
        expect($frame)->not->toContain('vendor/');
    }
});
