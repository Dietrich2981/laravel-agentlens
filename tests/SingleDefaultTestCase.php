<?php

namespace Agentlens\Tests;

/** Default channel is a plain `single` driver — nothing stack-like to mix into. */
class SingleDefaultTestCase extends ZeroConfigTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('logging.default', 'single');
    }
}
