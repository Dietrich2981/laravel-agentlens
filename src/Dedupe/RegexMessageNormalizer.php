<?php

namespace Agentlens\Dedupe;

use Agentlens\Contracts\MessageNormalizer;

/**
 * Default regex heuristic: UUIDs, long hex hashes and numbers become `#`,
 * so "Order 881 not found" and "Order 882 not found" share a fingerprint.
 */
class RegexMessageNormalizer implements MessageNormalizer
{
    public function normalize(string $message): string
    {
        // UUIDs.
        $message = preg_replace(
            '/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/',
            '#',
            $message
        ) ?? $message;

        // Long hex hashes (md5/sha/commit SHAs).
        $message = preg_replace('/\b[0-9a-fA-F]{8,}\b/', '#', $message) ?? $message;

        // ULID / cuid-ish tokens (long alphanumerics with mixed case+digits).
        $message = preg_replace('/\b(?=[A-Za-z0-9_-]{16,}\b)(?=[A-Za-z_-]*\d)[A-Za-z0-9_-]+\b/', '#', $message) ?? $message;

        // Plain numbers (ids, ports, counts).
        $message = preg_replace('/\b\d+(\.\d+)?\b/', '#', $message) ?? $message;

        return $message;
    }
}
