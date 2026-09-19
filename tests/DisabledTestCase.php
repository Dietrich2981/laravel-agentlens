<?php

namespace Agentlens\Tests;

/** Master switch off wins over everything, including force_agent_mode=true. */
class DisabledTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('agentlens.enabled', false);
    }
}
