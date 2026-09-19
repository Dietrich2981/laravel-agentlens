<?php

namespace Agentlens\Tests;

/**
 * Base case for human-mode tests: agent mode forced OFF before the provider
 * boots, proving the package registers nothing.
 */
class HumanTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('agentlens.force_agent_mode', false);
    }
}
