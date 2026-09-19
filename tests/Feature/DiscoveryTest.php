<?php

use Agentlens\Logging\AgentlensHandler;
use Illuminate\Support\Facades\Log;

test('fresh log file starts with a self-describing header', function () {
    Log::channel('agentlens')->error('first line');

    $raw = $this->agentlensRawLines();

    expect($raw)->toHaveCount(2);

    $header = json_decode($raw[0], true);

    expect($header['agentlens'])->toBe('1')
        ->and($header['hint'])->toContain('lvl=level')
        ->and($header['hint'])->toContain('window_s')
        ->and($this->agentlensLines())->toHaveCount(1);
});

test('header is written once per file, not per line', function () {
    Log::channel('agentlens')->error('one');
    Log::channel('agentlens')->error('two');
    Log::channel('agentlens')->error('three');

    $raw = $this->agentlensRawLines();
    $headers = array_filter($raw, fn ($line) => str_contains($line, '"agentlens"'));

    expect($headers)->toHaveCount(1)
        ->and($raw)->toHaveCount(4);
});

test('header can be disabled via config', function () {
    config()->set('agentlens.discovery.header', false);

    Log::channel('agentlens')->error('no header');

    expect($this->agentlensRawLines())->toHaveCount(1)
        ->and($this->agentlensLines())->toHaveCount(1);
});

test('handler exposes the resolved destination path', function () {
    expect($this->app->make(AgentlensHandler::class)->destinationPath())
        ->toBe($this->agentlensLogPath);
});

test('stderr pointer format contains the path', function () {
    $provider = $this->app->getProvider(Agentlens\AgentlensServiceProvider::class);
    $method = new ReflectionMethod($provider, 'buildStderrPointer');
    $method->setAccessible(true);

    expect($method->invoke($provider, '/tmp/x/agentlens.log'))
        ->toBe('[agentlens] compact deduped mirror for agents: /tmp/x/agentlens.log (human log unchanged)');
});

test('announcement is suppressed in unit tests and when disabled', function () {
    $provider = $this->app->getProvider(Agentlens\AgentlensServiceProvider::class);
    $should = new ReflectionMethod($provider, 'shouldAnnounce');
    $should->setAccessible(true);
    $flag = new ReflectionProperty($provider, 'announced');
    $flag->setAccessible(true);

    try {
        // Running under Pest: never announce (keeps test output clean).
        expect($should->invoke($provider))->toBeFalse();

        // Explicit opt-out also suppresses (checked before the test-env gate).
        config()->set('agentlens.discovery.stderr', false);
        $flag->setValue(null, false);

        expect($should->invoke($provider))->toBeFalse();

        // One-time guarantee: already announced => silent.
        config()->set('agentlens.discovery.stderr', true);

        expect($should->invoke($provider))->toBeFalse(); // still unit tests
        $flag->setValue(null, true);

        expect($should->invoke($provider))->toBeFalse();
    } finally {
        $flag->setValue(null, false);
    }
});
