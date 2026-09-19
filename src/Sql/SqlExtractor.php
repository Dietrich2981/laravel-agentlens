<?php

namespace Agentlens\Sql;

use Illuminate\Database\QueryException;

/**
 * Extracts the failed SQL for QueryException (preferred: the exception's own
 * sql+bindings; fallback: the LastQueryBuffer). Returns null for any other
 * exception type so plain RuntimeExceptions stay lean.
 */
final class SqlExtractor
{
    public static function extract(\Throwable $e, ?LastQueryBuffer $buffer = null, int $maxLength = 500): ?string
    {
        if (! $e instanceof QueryException) {
            return null;
        }

        $sql = $e->getSql();
        if (is_string($sql) && $sql !== '') {
            return static::interpolate($sql, $e->getBindings(), $maxLength);
        }

        return $buffer?->formatLastQuery($maxLength);
    }

    public static function interpolate(string $sql, array $bindings, int $maxLength = 500): string
    {
        foreach ($bindings as $binding) {
            $pos = strpos($sql, '?');
            if ($pos === false) {
                break;
            }
            $sql = substr_replace($sql, static::quote($binding), $pos, 1);
        }

        if (strlen($sql) > $maxLength) {
            $sql = substr($sql, 0, $maxLength).'…';
        }

        return $sql;
    }

    protected static function quote(mixed $binding): string
    {
        if ($binding === null) {
            return 'NULL';
        }

        if (is_bool($binding)) {
            return $binding ? '1' : '0';
        }

        if (is_int($binding) || is_float($binding)) {
            return (string) $binding;
        }

        if ($binding instanceof \DateTimeInterface) {
            return "'".$binding->format('Y-m-d H:i:s')."'";
        }

        if (! is_string($binding)) {
            $binding = is_scalar($binding) ? (string) $binding : '[complex]';
        }

        if (strlen($binding) > 200) {
            $binding = substr($binding, 0, 200).'…';
        }

        return "'".str_replace("'", "''", $binding)."'";
    }
}
