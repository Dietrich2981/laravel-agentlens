<?php

namespace Agentlens\Tests;

/**
 * True zero-config: nothing under `agentlens.*` or `logging.channels.agentlens`
 * is set — the provider must create the channel config itself and mix it
 * into the stack. Only agent mode is forced on; everything else is defaults.
 */
class ZeroConfigTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('agentlens.force_agent_mode', true);

        $this->singleLogPath = $this->tempLogPath('agentlens-single');

        // The agentlens channel file is the package DEFAULT
        // (storage/logs/agentlens.log) — track it for cleanup.
        $this->agentlensLogPath = $app->storagePath().'/logs/agentlens.log';

        $app['config']->set('logging.default', 'stack');
        $app['config']->set('logging.channels.stack', [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ]);
        $app['config']->set('logging.channels.single', [
            'driver' => 'single',
            'path' => $this->singleLogPath,
            'level' => 'debug',
        ]);
    }
}
