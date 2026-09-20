<?php

use Agentlens\Contracts\AgentDetector;

test('human mode leaves the stack untouched and writes no agent log', function () {
    expect(config('logging.channels.stack.channels'))->not->toContain('agentlens');

    Illuminate\Support\Facades\Log::channel('stack')->error('human readable as always');

    expect(file_get_contents($this->singleLogPath))->toContain('human readable as always')
        ->and(file_exists($this->agentlensLogPath))->toBeFalse();
});

test('human mode captures no exceptions', function () {
    $handler = $this->app->make(Illuminate\Contracts\Debug\ExceptionHandler::class);
    $handler->report(new RuntimeException('human boom'));

    expect(file_exists($this->agentlensLogPath))->toBeFalse()
        ->and(file_get_contents($this->singleLogPath))->toContain('human boom');
});

test('force mode fully bypasses the detector', function () {
    // Even a detector screaming "agent!" cannot enable the package when
    // force is false, and vice versa.
    $this->app->bind(AgentDetector::class, fn () => new class implements AgentDetector {
        public function isAgentContext(): bool
        {
            return true;
        }
    });

    $provider = $this->app->getProvider(Agentlens\AgentlensServiceProvider::class);

    expect($provider->isAgentMode())->toBeFalse();

    config()->set('agentlens.force_agent_mode', true);
    $this->app->bind(AgentDetector::class, fn () => new class implements AgentDetector {
        public function isAgentContext(): bool
        {
            return false;
        }
    });

    expect($provider->isAgentMode())->toBeTrue();
});

test('no artisan commands leak into human mode', function () {
    expect(fn () => $this->artisan('agentlens:flush'))
        ->toThrow(Symfony\Component\Console\Exception\CommandNotFoundException::class);
});
