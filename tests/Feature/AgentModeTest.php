<?php

use Agentlens\Exceptions\AgentlensExceptionReporter;
use Agentlens\Logging\AgentlensHandler;
use Illuminate\Support\Facades\Log;

test('agent channel is mixed into the active stack with zero config', function () {
    expect(config('logging.channels.stack.channels'))->toContain('agentlens');
});

test('stack log keeps human format while agentlens gets compact json', function () {
    Log::channel('stack')->error('Order processing failed', ['order_id' => 881]);

    $human = file_get_contents($this->singleLogPath);
    $lines = $this->agentlensLines();

    expect($human)->toContain('Order processing failed')
        ->and($human)->not->toContain('"lvl"')
        ->and($lines)->toHaveCount(1);

    $decoded = json_decode($lines[0], true);

    expect($decoded)->toMatchArray([
        'lvl' => 'error',
        'msg' => 'Order processing failed',
        'ctx' => ['order_id' => 881],
    ]);
});

test('50 identical exceptions produce exactly one full record plus one summary', function () {
    $reporter = $this->app->make(AgentlensExceptionReporter::class);
    $exception = new RuntimeException('Order processing failed');

    for ($i = 0; $i < 50; $i++) {
        $reporter->report($exception);
    }

    expect($this->agentlensLines())->toHaveCount(1);

    $this->app->make(AgentlensHandler::class)->flushFinal();

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(2);

    $summary = json_decode($lines[1], true);

    expect($summary['count'])->toBe(50)
        ->and($summary)->toHaveKey('window_s');
});

test('unhandled exceptions are captured via reportable without changing normal reporting', function () {
    $handler = $this->app->make(Illuminate\Contracts\Debug\ExceptionHandler::class);
    $exception = new RuntimeException('kaboom via handler');

    $handler->report($exception);

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true)['msg'])->toBe('kaboom via handler')
        ->and(file_get_contents($this->singleLogPath))->toContain('kaboom via handler');
});

test('stack-covered exceptions are written exactly once per report', function () {
    // Default stack already routes to agentlens, so the hook stays silent
    // and each exception produces exactly one record attempt.
    $handler = $this->app->make(Illuminate\Contracts\Debug\ExceptionHandler::class);
    $exception = new RuntimeException('exact counts');

    for ($i = 0; $i < 3; $i++) {
        $handler->report($exception);
    }

    $this->app->make(AgentlensHandler::class)->flushFinal();

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[1], true))->toMatchArray(['count' => 3]);
});
