<?php

namespace Agentlens\Tests;

/** SQL buffer opt-out: DB::listen() must never be registered. */
class NoSqlTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('agentlens.sql.enabled', false);
    }
}
