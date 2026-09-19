<?php

namespace Agentlens\Contracts;

interface AgentDetector
{
    public function isAgentContext(): bool;
}
