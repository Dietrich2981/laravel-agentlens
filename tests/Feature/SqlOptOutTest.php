<?php

use Agentlens\Sql\LastQueryBuffer;
use Illuminate\Support\Facades\DB;

test('sql opt-out registers no query listener', function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');

    DB::select('select 1 as one');

    expect($this->app->make(LastQueryBuffer::class)->getLast())->toBeNull();
});
