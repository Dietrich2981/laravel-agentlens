<?php

namespace Agentlens\Console;

use Agentlens\Logging\AgentlensHandler;
use Illuminate\Console\Command;

/**
 * On-demand totals for agents: reproduce the errors, run
 * `php artisan agentlens:flush`, then read the log — pending and
 * in-progress summaries are written immediately, no waiting for
 * window rollover or process shutdown.
 */
class FlushCommand extends Command
{
    protected $signature = 'agentlens:flush';

    protected $description = 'Write pending agentlens dedupe summaries to the log now';

    public function handle(): int
    {
        try {
            $this->getLaravel()->make(AgentlensHandler::class)->flushFinal();
        } catch (\Throwable) {
            // Flushing must never break scripts.
        }

        $this->info('Agentlens summaries flushed.');

        return self::SUCCESS;
    }
}
