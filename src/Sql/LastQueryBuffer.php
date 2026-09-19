<?php

namespace Agentlens\Sql;

/**
 * Holds ONLY the most recent query (constant memory). Populated via a single
 * DB::listen() listener that is registered lazily and only in agent mode.
 */
class LastQueryBuffer
{
    /** @var array{sql: string, bindings: array, time: float|null}|null */
    protected ?array $last = null;

    public function record(string $sql, array $bindings = [], ?float $time = null): void
    {
        $this->last = ['sql' => $sql, 'bindings' => $bindings, 'time' => $time];
    }

    /**
     * @return array{sql: string, bindings: array, time: float|null}|null
     */
    public function getLast(): ?array
    {
        return $this->last;
    }

    public function clear(): void
    {
        $this->last = null;
    }

    public function formatLastQuery(int $maxLength = 500): ?string
    {
        if ($this->last === null) {
            return null;
        }

        return SqlExtractor::interpolate($this->last['sql'], $this->last['bindings'], $maxLength);
    }
}
