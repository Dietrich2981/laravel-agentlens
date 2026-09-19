<?php

use Agentlens\Detection\EnvAndTtyAgentDetector;
use Agentlens\Tests\DisabledTestCase;
use Agentlens\Tests\HumanTestCase;
use Agentlens\Tests\NoReportTestCase;
use Agentlens\Tests\NoSqlTestCase;
use Agentlens\Tests\SingleDefaultTestCase;
use Agentlens\Tests\TestCase;
use Agentlens\Tests\ZeroConfigTestCase;

uses(TestCase::class)->in(
    'Unit',
    'Feature/AgentModeTest.php',
    'Feature/SqlContextTest.php',
    'Feature/TraceTrimmingTest.php',
    'Feature/CustomBindingsTest.php',
    'Feature/ProviderOptionsTest.php',
    'Feature/DiscoveryTest.php',
);

uses(HumanTestCase::class)->in('Feature/HumanModeTest.php');
uses(ZeroConfigTestCase::class)->in('Feature/ZeroConfigTest.php');
uses(SingleDefaultTestCase::class)->in('Feature/SingleDefaultTest.php');
uses(DisabledTestCase::class)->in('Feature/DisabledModeTest.php');
uses(NoSqlTestCase::class)->in('Feature/SqlOptOutTest.php');
uses(NoReportTestCase::class)->in('Feature/ExceptionOptOutTest.php');

beforeEach(function () {
    EnvAndTtyAgentDetector::clearCache();
});
