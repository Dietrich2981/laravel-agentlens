<?php

use Agentlens\Formatting\LogRecordDTO;
use Agentlens\Sql\LastQueryBuffer;
use Illuminate\Database\QueryException;
use Monolog\Level;
use Monolog\LogRecord;

test('empty message falls back to previous exception info', function () {
    $dto = LogRecordDTO::fromThrowable(new RuntimeException('', 0, new InvalidArgumentException('bad arg')));

    expect($dto->message)->toBe('InvalidArgumentException: bad arg');
});

test('empty message without previous falls back to the class name', function () {
    $dto = LogRecordDTO::fromThrowable(new RuntimeException(''));

    expect($dto->message)->toBe(RuntimeException::class);
});

test('from monolog record lowercases the level and extracts the exception', function () {
    $record = new LogRecord(
        new DateTimeImmutable,
        'test',
        Level::Error,
        'boom happened',
        ['exception' => $e = new RuntimeException('boom happened'), 'order_id' => 5],
        []
    );

    $dto = LogRecordDTO::fromMonologRecord($record);

    expect($dto->level)->toBe('error')
        ->and($dto->exceptionClass)->toBe(RuntimeException::class)
        ->and($dto->context)->toBe(['order_id' => 5])
        ->and($dto->file)->toBe($e->getFile());
});

test('plain monolog records never pick up buffered sql', function () {
    $buffer = new LastQueryBuffer;
    $buffer->record('select * from `orders`', []);

    $dto = LogRecordDTO::fromMonologRecord(
        new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'just info', [], []),
        $buffer
    );

    expect($dto->sql)->toBeNull()
        ->and($dto->level)->toBe('info');
});

test('query exception record with empty sql uses the buffer', function () {
    $buffer = new LastQueryBuffer;
    $buffer->record('delete from `sessions`', []);

    $record = new LogRecord(
        new DateTimeImmutable,
        'test',
        Level::Error,
        'lost',
        ['exception' => new QueryException('sqlite', '', [], new Exception('x'))],
        []
    );

    expect(LogRecordDTO::fromMonologRecord($record, $buffer)->sql)->toContain('delete from `sessions`');
});
