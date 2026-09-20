<?php

use Agentlens\Dedupe\ArrayDedupeStore;
use Agentlens\Dedupe\FingerprintGenerator;
use Agentlens\Dedupe\RegexMessageNormalizer;
use Agentlens\Formatting\CompactJsonFormatter;
use Agentlens\Formatting\LogRecordDTO;
use Agentlens\Formatting\TraceTrimmer;
use Agentlens\Logging\AgentlensHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;

function makeDirectHandler(Level $level = Level::Debug, bool $bubble = true): AgentlensHandler
{
    return new AgentlensHandler(
        new FingerprintGenerator(new RegexMessageNormalizer),
        new CompactJsonFormatter(new TraceTrimmer(3, true, '/app')),
        new ArrayDedupeStore,
        null,
        $level,
        $bubble
    );
}

function makeLogRecord(Level $level, string $message): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'test', $level, $message, [], []);
}

test('records below the handler level are ignored', function () {
    $handler = makeDirectHandler(Level::Error);

    expect($handler->handle(makeLogRecord(Level::Debug, 'too quiet')))->toBeFalse()
        ->and(file_exists($this->agentlensLogPath))->toBeFalse();
});

test('records at the handler level are emitted', function () {
    // bubble=false: Monolog convention returns true when emission stops bubbling.
    $handler = makeDirectHandler(Level::Error, false);

    expect($handler->handle(makeLogRecord(Level::Error, 'loud enough')))->toBeTrue();

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true)['msg'])->toBe('loud enough');
});

test('missing directories are created on first write', function () {
    $nested = sys_get_temp_dir().'/agentlens-nested-'.getmypid().'-'.str_replace('.', '', uniqid('', true));
    $path = $nested.'/agentlens.log';
    $this->tempFiles[] = $path;

    config()->set('logging.channels.agentlens.path', $path);

    Log::channel('agentlens')->error('nested boom');

    expect(file_exists($path))->toBeTrue();

    $lines = array_values(array_filter(array_map('trim', file($path) ?: [])));

    // Header legend + the data line.
    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[1], true)['msg'])->toBe('nested boom');

    @unlink($path);
    @rmdir($nested);
});

test('resetForTests reopens the stream cleanly', function () {
    Log::channel('agentlens')->error('before reset');

    $handler = $this->app->make(AgentlensHandler::class);
    $handler->resetForTests();

    Log::channel('agentlens')->error('after reset');

    expect($this->agentlensLines())->toHaveCount(2);
});

test('flushing twice does not duplicate summaries', function () {
    $handler = $this->app->make(AgentlensHandler::class);

    Log::channel('agentlens')->error('repeat me');
    Log::channel('agentlens')->error('repeat me');
    Log::channel('agentlens')->error('repeat me');

    $handler->flushFinal();
    $handler->flushFinal();

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[1], true)['count'])->toBe(3);
});

test('per-request flush leaves open windows alone, final flush reports them', function () {
    $handler = $this->app->make(AgentlensHandler::class);

    Log::channel('agentlens')->error('burst');
    Log::channel('agentlens')->error('burst');
    Log::channel('agentlens')->error('burst');

    // Safe per request (serve, Octane): no per-request summary spam.
    $handler->flushSummaries();

    expect($this->agentlensLines())->toHaveCount(1);

    // True process end: the total is reported once.
    $handler->flushFinal();

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[1], true)['count'])->toBe(3);
});
