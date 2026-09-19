<?php

use Agentlens\Sql\LastQueryBuffer;

test('buffer starts empty and clears cleanly', function () {
    $buffer = new LastQueryBuffer;

    expect($buffer->getLast())->toBeNull()
        ->and($buffer->formatLastQuery())->toBeNull();

    $buffer->record('select 1', [], 0.5);

    expect($buffer->getLast())->toBe(['sql' => 'select 1', 'bindings' => [], 'time' => 0.5]);

    $buffer->clear();

    expect($buffer->getLast())->toBeNull();
});

test('format interpolates bindings', function () {
    $buffer = new LastQueryBuffer;
    $buffer->record('select * from t where id = ? and name = ?', [7, 'ann']);

    expect($buffer->formatLastQuery())->toBe('select * from t where id = 7 and name = \'ann\'');
});
