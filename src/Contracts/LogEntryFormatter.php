<?php

namespace Agentlens\Contracts;

use Agentlens\Formatting\LogRecordDTO;

interface LogEntryFormatter
{
    /** Format one record as a single compact JSON line (no trailing newline). */
    public function format(LogRecordDTO $record): string;
}
