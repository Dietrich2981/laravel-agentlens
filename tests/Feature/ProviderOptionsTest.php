<?php

use Agentlens\Exceptions\AgentlensExceptionReporter;
use Agentlens\Logging\AgentlensHandler;
use Agentlens\Sql\LastQueryBuffer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

test('dedupe can be switched off at runtime', function () {
    config()->set('agentlens.dedupe.enabled', false);

    $reporter = $this->app->make(AgentlensExceptionReporter::class);
    $exception = new RuntimeException('no dedupe here');

    for ($i = 0; $i < 5; $i++) {
        $reporter->report($exception);
    }

    $this->app->make(AgentlensHandler::class)->flushSummaries();

    // No suppression, no summary — every occurrence is a full line.
    expect($this->agentlensLines())->toHaveCount(5);
});

test('application termination flushes the pending summary', function () {
    $reporter = $this->app->make(AgentlensExceptionReporter::class);
    $exception = new RuntimeException('terminate flush');

    for ($i = 0; $i < 3; $i++) {
        $reporter->report($exception);
    }

    expect($this->agentlensLines())->toHaveCount(1);

    $this->app->terminate();

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[1], true))->toMatchArray(['count' => 3]);
});

test('a real query lands in the last-query buffer via DB::listen', function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');

    DB::select('select 1 as one');

    $last = $this->app->make(LastQueryBuffer::class)->getLast();

    expect($last)->not->toBeNull()
        ->and($last['sql'])->toContain('select 1');
});

test('empty-sql query exception falls back to the buffered query', function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');

    DB::select('select 881 as id');

    $dto = Agentlens\Formatting\LogRecordDTO::fromThrowable(
        new QueryException('sqlite', '', [], new Exception('lost sql')),
        'error',
        [],
        $this->app->make(LastQueryBuffer::class),
    );

    expect($dto->sql)->toContain('881');
});

test('reporter never breaks the app, even with a broken channel', function () {
    config()->set('logging.channels.agentlens.driver', 'no-such-driver');
    Log::forgetChannel('agentlens');

    $reporter = $this->app->make(AgentlensExceptionReporter::class);

    expect(fn () => $reporter->report(new RuntimeException('broken channel')))->not->toThrow(Throwable::class)
        ->and(file_exists($this->agentlensLogPath))->toBeFalse();
});

test('cache dedupe store can be selected via config', function () {
    config()->set('agentlens.dedupe.store', 'cache');

    expect($this->app->make(Agentlens\Contracts\DedupeStore::class))
        ->toBeInstanceOf(Agentlens\Dedupe\CacheDedupeStore::class);

    Illuminate\Support\Facades\Log::channel('agentlens')->error('cached dedupe');
    Illuminate\Support\Facades\Log::channel('agentlens')->error('cached dedupe');

    expect($this->agentlensLines())->toHaveCount(1);
});

test('force-mode normalization covers env string variants', function (mixed $value, ?bool $expected) {
    $provider = $this->app->getProvider(Agentlens\AgentlensServiceProvider::class);
    $method = new ReflectionMethod($provider, 'normalizeForced');
    $method->setAccessible(true);

    expect($method->invoke($provider, $value))->toBe($expected);
})->with([
    'bool true' => [true, true],
    'bool false' => [false, false],
    'int 1' => [1, true],
    'int 0' => [0, false],
    'string true' => ['true', true],
    'string false' => ['false', false],
    'string 1' => ['1', true],
    'string 0' => ['0', false],
    'string yes' => ['yes', true],
    'string no' => ['no', false],
    'string on' => ['on', true],
    'string off' => ['off', false],
    'uppercase TRUE' => ['TRUE', true],
    'padded False' => ['  False  ', false],
    'empty string' => ['', null],
    'null' => [null, null],
    'unknown word' => ['maybe', null],
]);
