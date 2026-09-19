<?php

namespace Agentlens\Detection;

use Agentlens\Contracts\AgentDetector;

/**
 * Default agent-context detector: known agent CLI env vars + non-TTY stdout
 * heuristic for CLI processes.
 *
 * The result is cached for the whole process lifecycle, so detection runs at
 * most once (important for the "zero overhead in human mode" requirement).
 * Tests / Octane workers can reset it via ::clearCache().
 */
class EnvAndTtyAgentDetector implements AgentDetector
{
    /**
     * Known agent CLI marker env vars. Extend without subclassing via the
     * `agentlens.detect.env_vars` config array (merged at call time).
     *
     * @var string[]
     */
    public const AGENT_ENV_VARS = [
        'CLAUDECODE',       // Claude Code
        'CLAUDE_CODE',      // Claude Code (alt)
        'CURSOR_TRACE_ID',  // Cursor
        'CURSOR_AGENT',     // Cursor agent
        'GEMINI_CLI',       // Gemini CLI
        'CODEX_CLI',        // OpenAI Codex CLI
        'OPENAI_CODEX',     // OpenAI Codex (alt)
        'AIDER_CHAT',       // Aider
        'REPLIT_AGENT',     // Replit agent
        'DEVIN',            // Devin
        'COPILOT_AGENT',    // Copilot CLI agent
        'CONTINUE_DEV',     // Continue.dev
    ];

    /** @var bool|null process-wide cached verdict */
    protected static ?bool $cached = null;

    public static function clearCache(): void
    {
        static::$cached = null;
    }

    public function isAgentContext(): bool
    {
        if (static::$cached !== null) {
            return static::$cached;
        }

        static::$cached = $this->detect();

        return static::$cached;
    }

    protected function detect(): bool
    {
        foreach ($this->agentEnvVars() as $var) {
            $value = getenv($var);
            if ($value !== false && $value !== '') {
                return true;
            }
        }

        return $this->isPipedCli();
    }

    /**
     * @return string[]
     */
    protected function agentEnvVars(): array
    {
        $extra = [];
        try {
            if (function_exists('config')) {
                $extra = config('agentlens.detect.env_vars', []) ?? [];
            }
        } catch (\Throwable) {
            // Container not booted yet (e.g. detector used ultra-early).
            $extra = [];
        }

        return array_unique(array_merge(static::AGENT_ENV_VARS, array_values((array) $extra)));
    }

    /**
     * True when running under CLI with stdout redirected (pipe/file) rather
     * than an interactive terminal — the typical shape of an agent session
     * capturing `php artisan serve` / command output.
     */
    protected function isPipedCli(): bool
    {
        if (! in_array(PHP_SAPI, ['cli', 'phpdbg', 'cli-server'], true)) {
            return false;
        }

        return ! $this->stdoutIsTty();
    }

    /** Extracted for testability (subclass and override in tests). */
    protected function stdoutIsTty(): bool
    {
        if (function_exists('stream_isatty')) {
            try {
                return @stream_isatty(STDOUT);
            } catch (\Throwable) {
                return false;
            }
        }

        if (function_exists('posix_isatty')) {
            try {
                return @posix_isatty(STDOUT);
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }
}
