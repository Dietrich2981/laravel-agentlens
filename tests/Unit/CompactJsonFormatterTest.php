<?php

use Agentlens\Formatting\CompactJsonFormatter;
use Agentlens\Formatting\LogRecordDTO;
use Agentlens\Formatting\TraceTrimmer;

function makeFormatter(): CompactJsonFormatter
{
    return new CompactJsonFormatter(new TraceTrimmer(maxFrames: 3, skipVendorFrames: true, basePath: '/app'));
}

test('formats a plain record as one compact json line', function () {
    $line = makeFormatter()->format(new LogRecordDTO(
        level: 'error',
        message: 'Order processing failed',
        context: ['order_id' => 881],
    ));

    expect($line)->not->toContain("\n");

    $decoded = json_decode($line, true);

    expect($decoded)->toMatchArray([
        'lvl' => 'error',
        'msg' => 'Order processing failed',
        'ctx' => ['order_id' => 881],
        'count' => 1,
    ])->and($decoded)->not->toHaveKeys(['sql', 'at', 'trace_top']);
});

test('short keys and no ansi or box-drawing', function () {
    $line = makeFormatter()->format(new LogRecordDTO(
        level: 'error',
        message: 'boom',
        exceptionClass: RuntimeException::class,
        file: '/app/app/Foo.php',
        line: 42,
        trace: [],
    ));

    expect($line)->not->toContain("\x1b[")
        ->and($line)->not->toContain('level', 'message', 'context')
        ->and(json_decode($line, true))->toMatchArray([
            'lvl' => 'error',
            'msg' => 'boom',
            'at' => '/app/app/Foo.php:42',
        ]);
});

test('query exception carries sql, runtime exception does not', function () {
    $queryException = new Illuminate\Database\QueryException(
        'mysql',
        'insert into `orders` (...) values (?, ?)',
        [881, 'x'],
        new Exception('Duplicate entry')
    );

    $withSql = makeFormatter()->format(LogRecordDTO::fromThrowable($queryException));
    $withoutSql = makeFormatter()->format(LogRecordDTO::fromThrowable(new RuntimeException('plain')));

    expect(json_decode($withSql, true))->toHaveKey('sql')
        ->and(json_decode($withSql, true)['sql'])->toContain('insert into')
        ->and(json_decode($withSql, true)['sql'])->toContain('881')
        ->and(json_decode($withoutSql, true))->not->toHaveKey('sql');
});

test('sql is truncated to the configured max length', function () {
    $queryException = new Illuminate\Database\QueryException(
        'mysql',
        'select * from t where a = ?',
        [str_repeat('x', 600)],
        new Exception('x')
    );

    $decoded = json_decode(makeFormatter()->format(LogRecordDTO::fromThrowable($queryException, 'error', [], null, 100)), true);

    expect(mb_strlen($decoded['sql']))->toBeLessThanOrEqual(101);
});

test('summary line carries count and window', function () {
    $line = makeFormatter()->formatSummary('error', 'boom', 47, 10);
    $decoded = json_decode($line, true);

    expect($decoded)->toMatchArray(['lvl' => 'error', 'msg' => 'boom', 'count' => 47, 'window_s' => 10]);
});

test('malformed utf-8 never breaks encoding', function () {
    $line = makeFormatter()->format(new LogRecordDTO(
        level: 'error',
        message: "bad \xB1\x31 bytes",
    ));

    expect(json_decode($line))->not->toBeNull();
});

test('very long messages are truncated', function () {
    $line = makeFormatter()->format(new LogRecordDTO(
        level: 'error',
        message: str_repeat('a', 2000),
    ));

    $decoded = json_decode($line, true);

    expect(mb_strlen($decoded['msg']))->toBeLessThanOrEqual(1001)
        ->and($decoded['msg'])->toEndWith('…');
});
