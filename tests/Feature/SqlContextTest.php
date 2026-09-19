<?php

use Agentlens\Exceptions\AgentlensExceptionReporter;
use Illuminate\Database\QueryException;

test('query exception carries sql, runtime exception does not', function () {
    $reporter = $this->app->make(AgentlensExceptionReporter::class);

    $reporter->report(new QueryException(
        'mysql',
        'insert into `orders` (...) values (?, ?)',
        [881, 'x'],
        new Exception('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry')
    ));
    $reporter->report(new RuntimeException('plain failure'));

    $lines = $this->agentlensLines();

    expect($lines)->toHaveCount(2);

    $withSql = json_decode($lines[0], true);
    $withoutSql = json_decode($lines[1], true);

    expect($withSql)->toHaveKey('sql')
        ->and($withSql['sql'])->toContain('insert into')
        ->and($withoutSql)->not->toHaveKey('sql');
});

test('last query buffer holds only the most recent query', function () {
    $buffer = $this->app->make(Agentlens\Sql\LastQueryBuffer::class);

    $buffer->record('select 1', []);
    $buffer->record('select * from `orders` where id = ?', [881]);

    expect($buffer->formatLastQuery())->toContain('881')
        ->and($buffer->formatLastQuery())->not->toContain('select 1');
});
