<?php

use Agentlens\Contracts\DedupeStore;
use Agentlens\Dedupe\CacheDedupeStore;
use Agentlens\Dedupe\FingerprintGenerator;
use Agentlens\Exceptions\AgentlensExceptionReporter;
use Agentlens\Formatting\LogRecordDTO;
use Agentlens\Logging\AgentlensHandler;use Agentlens\Sql\LastQueryBuffer;
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

test('request termination writes no per-request spam, process end reports the total', function () {
    $reporter = $this->app->make(AgentlensExceptionReporter::class);
    $exception = new RuntimeException('terminate flush');

    for ($i = 0; $i < 3; $i++) {
        $reporter->report($exception);
    }

    expect($this->agentlensLines())->toHaveCount(1);

    // Framework termination (per request under serve/Octane): silent.
    $this->app->terminate();

    expect($this->agentlensLines())->toHaveCount(1);

    // True process end (PHP shutdown): one summary with the total.
    $this->app->make(AgentlensHandler::class)->flushFinal();

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

test('sweep summary keeps the original message across processes', function () {
    $this->app->singleton(
        DedupeStore::class,
        fn ($app) => new CacheDedupeStore($app['cache']->store('array'))
    );

    Log::channel('agentlens')->error('burst across instances');
    Log::channel('agentlens')->error('burst across instances');

    // The window goes quiet with no new sighting (backdate the clock,
    // including the sweep throttle so the next flush may run).
    $fp = $this->app->make(FingerprintGenerator::class)->forRecord(
        new LogRecordDTO(level: 'error', message: 'burst across instances')
    );
    $this->app['cache']->store('array')->put('agentlens:dedupe:seen:'.$fp, time() - 120);
    $this->app['cache']->store('array')->put('agentlens:dedupe:lastsweep', time() - 120);

    // Fresh handler = another request that never saw the burst.
    $this->app->make(AgentlensHandler::class)->close();
    $this->app->forgetInstance(AgentlensHandler::class);
    Log::forgetChannel('agentlens');

    Log::channel('agentlens')->error('something entirely different');

    $this->app->make(AgentlensHandler::class)->flushSummaries();

    $summaries = array_values(array_filter(
        $this->agentlensLines(),
        fn ($line) => isset(json_decode($line, true)['window_s'])
    ));

    expect($summaries)->toHaveCount(1)
        ->and(json_decode($summaries[0], true))->toMatchArray([
            'msg' => 'burst across instances',
            'count' => 2,
        ]);
});

test('force-mode normalization covers env string variants', function (mixed $value, ?bool $expected) {    $provider = $this->app->getProvider(Agentlens\AgentlensServiceProvider::class);
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
