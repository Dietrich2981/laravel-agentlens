<?php

namespace Agentlens\Contracts;

/**
 * Normalizes a log message before fingerprinting so that
 * "Order 881 not found" and "Order 882 not found" share one fingerprint.
 *
 * Replaceable via: app()->bind(MessageNormalizer::class, MyNormalizer::class)
 */
interface MessageNormalizer
{
    public function normalize(string $message): string;
}
