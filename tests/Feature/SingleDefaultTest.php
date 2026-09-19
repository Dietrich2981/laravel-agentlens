<?php

use Agentlens\Exceptions\AgentlensExceptionReporter;
use Illuminate\Support\Facades\Log;

test('non-stack default is left untouched but the channel still works directly', function () {
    expect(config('logging.default'))->toBe('single');

    Log::error('plain single log');

    expect(file_get_contents($this->singleLogPath))->toContain('plain single log')
        ->and(file_exists($this->agentlensLogPath))->toBeFalse();
});

test('exception hook still captures with a non-stack default', function () {
    $this->app->make(AgentlensExceptionReporter::class)->report(new RuntimeException('direct channel boom'));

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true)['msg'])->toBe('direct channel boom');
});
