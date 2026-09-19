<?php

namespace Agentlens\Tests;

/** Exception-hook opt-out: reportable() must never be registered. */
class NoReportTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('agentlens.exceptions.capture_unhandled', false);
    }
}
