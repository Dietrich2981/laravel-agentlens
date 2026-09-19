<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | When false, the package registers nothing at all: no log channel, no
    | exception hook, no query listener. Human-facing behaviour is then
    | byte-for-byte identical to an app without this package.
    */
    'enabled' => env('AGENTLENS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Agent mode override
    |--------------------------------------------------------------------------
    | null  = auto-detect (agent env vars + non-TTY stdout heuristic).
    | true  = force agent mode on (useful in tests / explicit opt-in).
    | false = force agent mode off (human mode, zero overhead).
    */
    'force_agent_mode' => env('AGENTLENS_FORCE', null),

    /*
    |--------------------------------------------------------------------------
    | Agent context detection
    |--------------------------------------------------------------------------
    | `env_vars` is merged with EnvAndTtyAgentDetector::AGENT_ENV_VARS.
    | Add your own agent's marker variable here instead of forking the class.
    */
    'detect' => [
        'env_vars' => [],
    ],

    'dedupe' => [
        'enabled' => true,
        'window_seconds' => 10,
        // array = in-memory (per process/request). cache = shared Laravel
        // cache store, required for Octane workers to dedupe across requests.
        'store' => env('AGENTLENS_DEDUPE_STORE', 'array'), // array|cache
    ],

    'trace' => [
        'max_frames' => 3,
        'skip_vendor_frames' => true,
    ],

    'sql' => [
        // The last-query buffer (DB::listen, last query only) is registered
        // lazily and ONLY in agent mode, so human mode pays zero overhead.
        'enabled' => true,
        'include_failed_query' => true,
        'max_query_length' => 500,
    ],

    'exceptions' => [
        'capture_unhandled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log channel
    |--------------------------------------------------------------------------
    | Resolved lazily at first write, so tests / config changes keep working
    | without purging resolved channels. Null path = storage/logs/agentlens.log.
    */
    'logging' => [
        'channel' => 'agentlens',
        'path' => env('AGENTLENS_LOG_PATH'),
        'level' => env('AGENTLENS_LOG_LEVEL', 'debug'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent discovery
    |--------------------------------------------------------------------------
    | Agents cannot benefit from a mirror file they never open:
    | `header` writes a schema legend as the first line of every fresh
    | agentlens.log (valid JSON, so line-parsers keep working);
    | `stderr` prints a one-time pointer per process (agent mode + CLI only,
    | STDERR — machine-readable STDOUT such as `artisan --json` stays clean).
    */
    'discovery' => [
        'header' => true,
        'stderr' => env('AGENTLENS_DISCOVERY_STDERR', true),
    ],
];
