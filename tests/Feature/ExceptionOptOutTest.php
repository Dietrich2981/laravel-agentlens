<?php

use Agentlens\Exceptions\AgentlensExceptionReporter;

class SpyReporter extends AgentlensExceptionReporter
{
    public static int $calls = 0;

    public function report(Throwable $e): void
    {
        static::$calls++;
        parent::report($e);
    }
}

beforeEach(function () {
    SpyReporter::$calls = 0;
});

test('exception hook opt-out registers no reportable callback', function () {
    $this->app->singleton(AgentlensExceptionReporter::class, fn () => new SpyReporter);

    $handler = $this->app->make(Illuminate\Contracts\Debug\ExceptionHandler::class);
    $handler->report(new RuntimeException('opted out boom'));

    // The hook never fired…
    expect(SpyReporter::$calls)->toBe(0)
        // …but normal Laravel reporting is intact: human log has it…
        ->and(file_get_contents($this->singleLogPath))->toContain('opted out boom')
        // …and the stack-mixed channel still sees it exactly once.
        ->and($this->agentlensLines())->toHaveCount(1);
});
