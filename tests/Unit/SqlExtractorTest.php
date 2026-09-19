<?php

use Agentlens\Sql\LastQueryBuffer;
use Agentlens\Sql\SqlExtractor;
use Illuminate\Database\QueryException;

function queryException(string $sql, array $bindings = []): QueryException
{
    return new QueryException('sqlite', $sql, $bindings, new Exception('boom'));
}

test('binding interpolation covers every type', function () {
    expect(SqlExtractor::interpolate('a=? b=? c=? d=? e=? f=?', [null, true, false, 42, 1.5, 'x']))
        ->toBe('a=NULL b=1 c=0 d=42 e=1.5 f=\'x\'');
});

test('dates and quotes are escaped', function () {
    expect(SqlExtractor::interpolate('a=? b=?', [new DateTimeImmutable('2026-01-02 03:04:05'), "O'Brien"]))
        ->toBe('a=\'2026-01-02 03:04:05\' b=\'O\'\'Brien\'');
});

test('long bindings and long queries are truncated', function () {
    $out = SqlExtractor::interpolate('a=?', [str_repeat('x', 600)]);

    expect($out)->toStartWith('a=\'xxx')
        ->and(mb_strlen($out))->toBeLessThan(250);

    $long = SqlExtractor::interpolate(str_repeat('y', 600), [], 100);

    expect(mb_strlen($long))->toBe(101)
        ->and($long)->toEndWith('…');
});

test('extra bindings beyond placeholders are ignored', function () {
    expect(SqlExtractor::interpolate('select 1', [1, 2, 3]))->toBe('select 1');
});

test('non-scalar bindings become a placeholder', function () {
    expect(SqlExtractor::interpolate('a=? b=?', [['x'], new stdClass]))->toBe('a=\'[complex]\' b=\'[complex]\'');
});

test('extract returns null for non-query exceptions even with a full buffer', function () {
    $buffer = new LastQueryBuffer;
    $buffer->record('select * from `orders`', []);

    expect(SqlExtractor::extract(new RuntimeException('plain'), $buffer))->toBeNull();
});

test('extract falls back to the buffer when the exception sql is empty', function () {
    $buffer = new LastQueryBuffer;
    $buffer->record('select * from `orders` where id = ?', [881]);

    expect(SqlExtractor::extract(queryException(''), $buffer))->toContain('881')
        ->and(SqlExtractor::extract(queryException(''), new LastQueryBuffer))->toBeNull()
        ->and(SqlExtractor::extract(queryException(''), null))->toBeNull();
});

test('extract prefers the exception sql and interpolates bindings', function () {
    expect(SqlExtractor::extract(queryException('select * from t where id = ?', [7])))->toBe('select * from t where id = 7');
});
