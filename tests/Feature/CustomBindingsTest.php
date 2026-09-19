<?php

use Agentlens\Contracts\AgentDetector;
use Agentlens\Contracts\LogEntryFormatter;
use Agentlens\Contracts\MessageNormalizer;
use Agentlens\Dedupe\FingerprintGenerator;
use Agentlens\Logging\AgentlensHandler;
use Illuminate\Support\Facades\Log;

test('custom agent detector can be bound via the container', function () {
    config()->set('agentlens.force_agent_mode', null);

    $this->app->bind(AgentDetector::class, fn () => new class implements AgentDetector {
        public function isAgentContext(): bool
        {
            return true;
        }
    });

    $provider = $this->app->getProvider(Agentlens\AgentlensServiceProvider::class);

    expect($provider->isAgentMode())->toBeTrue();
});

test('custom message normalizer can be bound via the container', function () {
    $this->app->bind(MessageNormalizer::class, fn () => new class implements MessageNormalizer {
        public function normalize(string $message): string
        {
            return 'CONSTANT'; // collapse everything into one fingerprint
        }
    });

    // Re-resolve the pipeline so the custom normalizer is picked up.
    try {
        $this->app->make(AgentlensHandler::class)->close();
    } catch (\Throwable) {
    }
    $this->app->forgetInstance(FingerprintGenerator::class);
    $this->app->forgetInstance(AgentlensHandler::class);
    Log::forgetChannel('agentlens');

    Log::channel('agentlens')->error('totally different message one');
    Log::channel('agentlens')->error('nothing alike message two');

    expect($this->agentlensLines())->toHaveCount(1);
});

test('custom formatter can be bound via the container', function () {
    $this->app->bind(LogEntryFormatter::class, fn () => new class implements LogEntryFormatter {
        public function format(Agentlens\Formatting\LogRecordDTO $record): string
        {
            return 'CUSTOM:'.$record->message;
        }
    });

    try {
        $this->app->make(AgentlensHandler::class)->close();
    } catch (\Throwable) {
    }
    $this->app->forgetInstance(AgentlensHandler::class);
    Log::forgetChannel('agentlens');

    Log::channel('agentlens')->error('hello custom');

    expect($this->agentlensLines())->toBe(['CUSTOM:hello custom']);
});
