<?php

namespace Agentlens\Exceptions;

use Illuminate\Support\Facades\Log;

/**
 * Writes the compact agent-facing copy of an unhandled exception to the
 * `agentlens` channel. Always called IN ADDITION to the normal report flow —
 * human-facing behaviour never changes. Never throws.
 */
class AgentlensExceptionReporter
{
    public function report(\Throwable $e): void
    {
        try {
            Log::channel($this->channel())->error($e->getMessage() !== '' ? $e->getMessage() : get_class($e), [
                'exception' => $e,
            ]);
        } catch (\Throwable) {
            // Logging must never break the application.
        }
    }

    protected function channel(): string
    {
        try {
            if (function_exists('config')) {
                $channel = config('agentlens.logging.channel', 'agentlens');

                return is_string($channel) && $channel !== '' ? $channel : 'agentlens';
            }
        } catch (\Throwable) {
            // fall through
        }

        return 'agentlens';
    }
}
