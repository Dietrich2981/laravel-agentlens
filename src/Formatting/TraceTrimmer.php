<?php

namespace Agentlens\Formatting;

/**
 * Keeps at most maxFrames non-vendor frames; vendor frames collapse into one
 * "(vendor skipped: N frames)" marker. Pure, no framework calls.
 */
class TraceTrimmer
{
    public function __construct(
        protected int $maxFrames = 3,
        protected bool $skipVendorFrames = true,
        protected string $basePath = '',
    ) {}

    /**
     * @param array<int, array> $trace raw Throwable::getTrace() frames
     * @return string[]
     */
    public function trim(array $trace): array
    {
        $out = [];
        $skippedVendor = 0;

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            $isVendor = $this->skipVendorFrames && $this->isVendorFrame((string) $file);

            if ($isVendor) {
                $skippedVendor++;
                continue;
            }

            if (count($out) >= $this->maxFrames) {
                // Non-vendor frame beyond budget: still count it, don't emit.
                continue;
            }

            $out[] = $this->formatFrame($frame);
        }

        if ($skippedVendor > 0) {
            $out[] = "(vendor skipped: {$skippedVendor} frames)";
        }

        return $out;
    }

    public function formatFrame(array $frame): string
    {
        $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '{main}');
        $location = '';

        if (isset($frame['file'])) {
            $location = $this->relativize((string) $frame['file']);
            if (isset($frame['line'])) {
                $location .= ':'.$frame['line'];
            }
        }

        return $location !== '' ? "{$call} ({$location})" : $call;
    }

    protected function isVendorFrame(string $file): bool
    {
        if ($file === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $file);

        return str_contains($normalized, '/vendor/') || str_starts_with($normalized, 'vendor/');
    }

    protected function relativize(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);

        if ($this->basePath !== '') {
            $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';
            if (str_starts_with($normalized, $base)) {
                return substr($normalized, strlen($base));
            }
        }

        return $normalized;
    }
}
