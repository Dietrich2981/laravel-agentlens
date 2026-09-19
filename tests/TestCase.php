<?php

namespace Agentlens\Tests;

use Agentlens\AgentlensServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected ?string $agentlensLogPath = null;

    protected ?string $singleLogPath = null;

    /** @var string[] extra temp files to delete in tearDown */
    protected array $tempFiles = [];

    protected function getPackageProviders($app): array
    {
        return [AgentlensServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Default: agent mode ON so feature tests exercise the agent pipeline.
        // Human-mode tests override via HumanTestCase. Detector cache is
        // cleared before each test (see tests/Pest.php).
        $app['config']->set('agentlens.force_agent_mode', true);
        $app['config']->set('agentlens.dedupe.window_seconds', 60);

        $this->agentlensLogPath = $this->tempLogPath('agentlens-test');
        $this->singleLogPath = $this->tempLogPath('agentlens-single');

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
        $app['config']->set('logging.channels.agentlens', [
            'driver' => 'agentlens',
            'level' => 'debug',
            'path' => $this->agentlensLogPath,
        ]);
    }

    protected function tempLogPath(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.getmypid().'-'.str_replace('.', '', uniqid('', true)).'.log';
        $this->tempFiles[] = $path;

        return $path;
    }

    protected function closeLogStreams(): void
    {
        // Release every open log stream BEFORE deleting files: on Windows an
        // open handle blocks both reopening and unlinking the same path.
        try {
            foreach (['stack', 'single', 'agentlens'] as $channel) {
                try {
                    \Illuminate\Support\Facades\Log::channel($channel)->close();
                } catch (\Throwable) {
                    // Channel never resolved (e.g. agentlens in human mode).
                }
                try {
                    \Illuminate\Support\Facades\Log::forgetChannel($channel);
                } catch (\Throwable) {
                    // ignore
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        try {
            $this->app->make(\Agentlens\Logging\AgentlensHandler::class)->close();
        } catch (\Throwable) {
            // ignore (human mode never creates the handler)
        }
    }

    protected function tearDown(): void
    {
        $this->closeLogStreams();

        foreach ([$this->agentlensLogPath, $this->singleLogPath, ...$this->tempFiles] as $file) {
            if (is_string($file) && $file !== '' && file_exists($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    /** @return string[] non-empty data lines (schema header excluded) */
    protected function agentlensLines(): array
    {
        return array_values(array_filter(
            $this->agentlensRawLines(),
            function ($line) {
                $decoded = json_decode($line, true);

                return ! (is_array($decoded) && array_key_exists('agentlens', $decoded));
            }
        ));
    }

    /** @return string[] all non-empty lines, header included */
    protected function agentlensRawLines(): array
    {
        if (! is_string($this->agentlensLogPath) || ! file_exists($this->agentlensLogPath)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', file($this->agentlensLogPath) ?: []),
            fn ($line) => $line !== ''
        ));
    }
}
