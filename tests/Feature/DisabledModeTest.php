<?php

use Illuminate\Support\Facades\Log;

test('master switch off beats force on: stack untouched, nothing captured', function () {
    expect(config('agentlens.force_agent_mode'))->toBeTrue();

    $provider = $this->app->getProvider(Agentlens\AgentlensServiceProvider::class);

    expect($provider->isAgentMode())->toBeFalse()
        ->and(config('logging.channels.stack.channels'))->not->toContain('agentlens');

    Log::channel('stack')->error('disabled boom');

    $handler = $this->app->make(Illuminate\Contracts\Debug\ExceptionHandler::class);
    $handler->report(new RuntimeException('disabled exception'));

    expect(file_get_contents($this->singleLogPath))->toContain('disabled boom')
        ->and(file_exists($this->agentlensLogPath))->toBeFalse();
});
