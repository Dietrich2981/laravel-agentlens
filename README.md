# agentlens — runtime logs your AI agent can actually read

[![Tests](https://github.com/Dietrich2981/laravel-agentlens/actions/workflows/tests.yml/badge.svg)](https://github.com/Dietrich2981/laravel-agentlens/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/agentlens/agentlens.svg)](https://packagist.org/packages/agentlens/agentlens)
[![Total Downloads](https://img.shields.io/packagist/dt/agentlens/agentlens.svg)](https://packagist.org/packages/agentlens/agentlens)
[![PHP Version](https://img.shields.io/packagist/php-v/agentlens/agentlens.svg)](https://packagist.org/packages/agentlens/agentlens)
[![License](https://img.shields.io/packagist/l/agentlens/agentlens.svg)](https://packagist.org/packages/agentlens/agentlens)

When your Laravel app crashes in dev, the log is a wall of noise: 80-frame stack traces, the same error repeated 50 times by a retry loop, ANSI colors and Whoops boxes. You can skim past it — but an AI agent debugging your app (Claude Code, Cursor, Gemini CLI, …) burns thousands of tokens on the exact same wall of text.

`agentlens` fixes that with one command:

```bash
composer require agentlens/agentlens
```

That's the whole setup. From then on, whenever an AI agent runs your app, it gets a **second log file** with the same events in compact form: one JSON line per error, traces trimmed to 3 frames, 50 repeats collapsed into a single `{"count": 50}` summary.

And the part that matters most: **you notice nothing.** Your terminal, Telescope and `laravel.log` stay byte-for-byte identical. When no agent is around, the package registers nothing at all — no channel, no listeners, no overhead.

**The difference, measured** ([benchmarks](#benchmarks)): 50 identical crashes go from ~170 KB / ~42,000 tokens of repeated stack traces to 3 lines / ~226 tokens — a 99.5% reduction.

<details>
<summary>▶ Step-by-step demo: from 50 crashes to 3 log lines</summary>

![demo](demo.gif)

</details>

## Install

```bash
composer require agentlens/agentlens
```

The `AgentlensServiceProvider` is registered via Laravel package auto-discovery. No `bootstrap/app.php` changes, no config publishing required. To publish the config:

```bash
php artisan vendor:publish --tag=agentlens-config
```

Force agent mode on/off explicitly (useful for trying it out or in tests):

```bash
AGENTLENS_FORCE=true php artisan serve   # compact JSON in storage/logs/agentlens.log
AGENTLENS_FORCE=false php artisan serve  # byte-identical behaviour to no package
```

## How it works

1. **Detect (once per lifecycle).** `EnvAndTtyAgentDetector` checks known agent CLI env vars (`CLAUDECODE`, `CURSOR_TRACE_ID`, `GEMINI_CLI`, … — full list in `AGENT_ENV_VARS`, extendable via `agentlens.detect.env_vars`) plus a non-TTY-stdout heuristic for CLI processes. The verdict is cached; detection never runs per log call.
2. **Zero-overhead gate.** If the verdict is negative, the provider returns from `boot()` early: no log channel, no exception hook, no `DB::listen()`. Not "disabled by a flag" — physically not registered.
3. **Agent mode.** The `agentlens` Monolog channel is appended to the active `stack`, unhandled exceptions get a compact copy via `Handler::reportable()`, and only the *last* query is buffered via `DB::listen()` (constant memory) so `QueryException`s can carry their failed SQL.
4. **Compact + dedupe.** Each record becomes one JSON line (short keys: `lvl`, `msg`, `at`, `ctx`, `sql`, `count`, `trace_top`), trace trimmed to `max_frames` with vendor frames collapsed, repeats inside `window_seconds` suppressed and counted, drained windows flushed as one summary line on termination.

```json
{"lvl":"error","msg":"SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry","at":"app/Http/Controllers/OrderController.php:42","ctx":{"order_id":881},"sql":"insert into `orders` (...) values (...)","count":1,"trace_top":["OrderController::store (app/Http/Controllers/OrderController.php:42)","(vendor skipped: 27 frames)"]}
{"lvl":"error","msg":"SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry","count":50,"window_s":10}
```

## Deployment topologies

Agent detection is process-local: it sees the environment of the PHP process running your code, not who sends the HTTP requests. So:

| Who runs the PHP process | Agent does | What you do |
|---|---|---|
| Agent: `php artisan serve`, `artisan` commands, tests, queues, agent-started Octane | everything | nothing — zero-config |
| Human/system: Herd, Sail, Forge, Vapor… | sends HTTP | one line in `.env`: `AGENTLENS_FORCE=true` (remove after debugging, don't commit) |
| Human | human | nothing — the package sleeps, zero overhead |

For the middle row no code changes are needed: `.env` is read on every request in any SAPI, so the next agent request starts writing `agentlens.log`. (Same single flag via `fastcgi_param AGENTLENS_FORCE true;` in nginx or `environment:` in docker-compose works too.)

## On-demand totals

Repeat totals are event-driven: they are written on window rollover, process shutdown, or the per-request sweep — never while a burst is still coming in, because the total is unknowable until the burst pauses. So an agent that reads the log right after reproducing a bug sees the full error but a stale count.

`php artisan agentlens:flush` (registered in agent mode only) closes that gap: it writes pending and in-progress summaries immediately. Debug ritual: reproduce → flush → read, and the totals are exact with no waiting.

## Agent discovery

A mirror file the agent never opens is worthless, so the package announces itself — agent mode only, human mode stays silent:

1. **Schema header.** A fresh `agentlens.log` starts with a self-describing line (valid JSON, so line-parsers keep working):
   ```json
   {"agentlens":"1","hint":"compact deduped runtime log for AI agents: lvl=level msg=message at=file:line ..."}
   ```
   Written once per file (under an exclusive lock, so concurrent workers can't duplicate it). Disable with `'discovery.header' => false`.
2. **STDERR pointer.** Once per process on boot: `[agentlens] compact deduped mirror for agents: storage/logs/agentlens.log (human log unchanged)`. STDERR — deliberately, so machine-readable STDOUT (`artisan route:list --json`) never breaks. Skipped under unit tests. Disable with `AGENTLENS_DISCOVERY_STDERR=false` / `'discovery.stderr' => false`.
3. **(Manual, recommended) AGENTS.md.** For repo-level persistence, copy this block into your `AGENTS.md` / `CLAUDE.md`:
   ```md
   ## Logs (agentlens)
   When debugging runtime errors: reproduce the bug, run `php artisan agentlens:flush`, then read `storage/logs/agentlens.log`. The flush matters: without it, repeated-error totals appear only after the window rolls over or the process ends. The log is compact deduped JSON (`lvl/msg/at/ctx/sql/count/trace_top`, summaries carry `window_s`); the human log `storage/logs/laravel.log` holds the same events, verbose.
   ```

## Benchmarks

Measured with `php benchmarks/bench.php` (PHP 8.3, no framework boot — pure hot-path cost):

### Output size: 50× repeated QueryException with a 30-frame trace

| | bytes | ~tokens (bytes/4) |
|---|---|---|
| Before (50× full Laravel trace) | 169,650 | ~42,413 |
| After (header + 1 full line + 1 summary, actual file on disk) | 904 | ~226 |
| **Reduction** | | **99.5%** |

Breakdown of the 904 bytes: schema header ~346, full compact line ~437, summary line ~118.

### Output size: single error / two distinct errors (no dedupe involved)

| | bytes | ~tokens | reduction |
|---|---|---|---|
| Single error: before → after | 3,393 → 762 | ~848 → ~191 | 77.5% |
| Two distinct errors: before → after | 6,786 → 1,130 | ~1,697 → ~283 | 83.3% |

Here only the compact format and trace trimming work (nothing repeats, so no summaries). Note the ~346-byte header is paid once per file — every further error adds just its own ~400-byte line.

### Hot-path cost (per operation)

| Operation | Time |
|---|---|
| Agent detection (cached verdict — the *only* thing that runs in human mode) | ~48 ns |
| Dedupe gate (`shouldEmit` + increment) | ~145 ns |
| Fingerprint (regex normalize + sha1) | ~690 ns |
| Compact format of a 30-frame exception | ~5.7 µs |

For perspective: a single `fwrite` of one log line costs more than the entire agentlens pipeline. In human mode the cost is one ~48 ns cached boolean per lifecycle plus zero registered listeners.

## Configuration

`config/agentlens.php` (all defaults work without publishing):

```php
return [
    'enabled' => env('AGENTLENS_ENABLED', true),
    'force_agent_mode' => env('AGENTLENS_FORCE', null), // null = auto-detect
    'detect' => ['env_vars' => []],                     // merged with AGENT_ENV_VARS
    'dedupe' => ['enabled' => true, 'window_seconds' => env('AGENTLENS_DEDUPE_WINDOW', 10), 'store' => env('AGENTLENS_DEDUPE_STORE', 'array')],
    'trace' => ['max_frames' => 3, 'skip_vendor_frames' => true],
    'sql' => ['enabled' => true, 'include_failed_query' => true, 'max_query_length' => 500],
    'exceptions' => ['capture_unhandled' => true],
    'logging' => ['channel' => 'agentlens', 'path' => env('AGENTLENS_LOG_PATH'), 'level' => 'debug'],
    'discovery' => ['header' => true, 'stderr' => env('AGENTLENS_DISCOVERY_STDERR', true)],
];
```

## Swapping implementations via the container

All three public contracts are bound as singletons and replaceable with `app()->bind()` / `app()->singleton()`:

```php
use Agentlens\Contracts\AgentDetector;
use Agentlens\Contracts\DedupeStore;
use Agentlens\Contracts\LogEntryFormatter;
use Agentlens\Contracts\MessageNormalizer;

// Custom agent detector (e.g. your in-house agent's marker file / header)
app()->bind(AgentDetector::class, fn () => new class implements AgentDetector {
    public function isAgentContext(): bool
    {
        return file_exists('/tmp/my-agent-active');
    }
});

// Custom message normalizer for fingerprinting (regex heuristic not enough?)
app()->bind(MessageNormalizer::class, MyNormalizer::class);

// Custom formatter / dedupe store
app()->singleton(LogEntryFormatter::class, MyFormatter::class);
app()->singleton(DedupeStore::class, fn ($app) => new \Agentlens\Dedupe\CacheDedupeStore($app['cache']->store('redis')));
```

`force_agent_mode` always bypasses the detector entirely (including custom ones) — that is the intended seam for tests.

## Octane compatibility

- Detection verdict is process-cached env state — safe in workers.
- `ArrayDedupeStore` prunes expired entries past 1000 keys, but for workers set `'dedupe.store' => 'cache'` so repeats dedupe *across requests* on the same worker.
- Same for `php artisan serve` / FPM: every request bootstraps a fresh app, so the in-memory `array` store cannot dedupe across requests there either — use the `cache` store (`AGENTLENS_DEDUPE_STORE=cache`) when errors are triggered via HTTP.
- Two-tier summary flush: framework termination / Octane `RequestTerminated` drain completed windows only (safe per request, never spams); PHP shutdown drains in-progress windows too — fully on CLI, expired-only sweep on web SAPIs (where shutdown fires per request while the process lives on).
- The per-request flush also sweeps expired open windows (throttled to one sweep per window on shared stores), so a burst followed by silence is reported as soon as any later request ends — even an unrelated one, with the original message attached. Only a forever-idle, hard-killed process can orphan a trailing count (the full record itself is always emitted immediately).
- The log stream is append-mode with `flock()`; the path is resolved lazily per write.

## Design decisions (locked for v1)

1. **Dedupe emits a summary line when the window drains** (not a streaming in-place counter update): a log file is append-only, so mutating the already-written line is impossible without fragile bookkeeping. `count` on a full line is always 1 on first emission; the summary's `count` is the total occurrences in the window *including* the emitted one.
2. **No double-writes.** The exception hook checks at report time whether the default stack already routes to `agentlens` — if yes, it stays silent and lets the stack carry the single record. The direct write only fires when the stack would NOT capture the exception (e.g. default is a plain `single` channel). Dedupe remains as a backstop for any residual duplicates, so `count` is exact in the common case: 50 crashing requests report `count: 50`.
3. **Fingerprint normalization is a regex heuristic** (`\d+` → `#`, UUIDs → `#`, long hex/ULID-ish tokens → `#`) as the default, with `MessageNormalizer` replaceable via the container for domain-specific templates.
4. **Package / namespace is `agentlens/agentlens` → `Agentlens\`.** Rename the vendor segment when publishing under your own Packagist account; it appears only in `composer.json` and the PSR-4 prefix.

## How this differs from PAO (`laravel/pao`)

| | `laravel/pao` | `agentlens` |
|---|---|---|
| Target output | Test runners & static analyzers (PHPUnit/Pest/PHPStan) | **Runtime** logs & unhandled exceptions while the app is live |
| When it helps | CI / `composer test` sessions | `php artisan serve`, `sail up`, curl-against-dev-server debug loops |
| Mechanism | Reformats tool output | Extra Monolog channel + `reportable()` hook, human output untouched |
| Repetition | N/A | Fingerprint dedupe with windowed summaries (retry-loop storms) |
| Scope | Framework-agnostic-ish output filters | Laravel-specific (LogManager, ExceptionHandler, `DB::listen`, Octane) |

Use both: PAO for red test output, agentlens for red runtime output.

## Compatibility

PHP `^8.2`, Laravel `^11.0 || ^12.0 || ^13.0` (tested on 12 via Orchestra Testbench; the exception hook uses only the stable `ExceptionHandler::reportable()` contract present across 11–13). Monolog `^3`.

## Tests

```bash
composer install
./vendor/bin/pest
```
