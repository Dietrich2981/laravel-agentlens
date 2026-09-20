<?php

namespace Agentlens\Contracts;

/**
 * Optional capability for DedupeStore implementations: drain in-progress
 * (unexpired) windows. Called once at true process end (PHP shutdown), as
 * opposed to flushSummaries() which only drains completed windows and is
 * safe to call per request.
 *
 * Split exists because under `php artisan serve` (and Octane) the framework
 * termination callbacks run per request while the process lives on — draining
 * open windows there would emit a misleading summary per request.
 */
interface FlushesOpenWindows
{
    /**
     * Drain open windows.
     *
     * @param bool $onlyExpired when true, report only windows that already
     *                          expired (quiet windows). Used on web SAPIs where
     *                          shutdown fires per request — never spams, never
     *                          double-reports; recurring errors still surface
     *                          via rollover on the next request.
     * @return array<string, int> [fingerprint => total occurrences]
     */
    public function flushOpenWindows(int $windowSeconds, bool $onlyExpired = false): array;
}
