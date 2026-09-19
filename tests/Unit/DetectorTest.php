<?php

use Agentlens\Detection\EnvAndTtyAgentDetector;

class TtyStubDetector extends EnvAndTtyAgentDetector
{
    public static bool $tty = true;

    protected function stdoutIsTty(): bool
    {
        return static::$tty;
    }
}

beforeEach(function () {
    foreach (EnvAndTtyAgentDetector::AGENT_ENV_VARS as $var) {
        putenv($var);
    }
    EnvAndTtyAgentDetector::clearCache();
    TtyStubDetector::clearCache();
});

afterEach(function () {
    foreach (EnvAndTtyAgentDetector::AGENT_ENV_VARS as $var) {
        putenv($var);
    }
    EnvAndTtyAgentDetector::clearCache();
});

test('detects agent context from known env vars', function (string $var) {
    putenv("{$var}=1");

    expect((new TtyStubDetector)->isAgentContext())->toBeTrue();
})->with(EnvAndTtyAgentDetector::AGENT_ENV_VARS);

test('empty env var value does not trigger detection', function () {
    putenv('CLAUDECODE=');
    TtyStubDetector::$tty = true;

    expect((new TtyStubDetector)->isAgentContext())->toBeFalse();
});

test('falls back to non-TTY heuristic in CLI', function () {
    TtyStubDetector::$tty = false;

    expect((new TtyStubDetector)->isAgentContext())->toBeTrue();

    TtyStubDetector::clearCache();
    TtyStubDetector::$tty = true;

    expect((new TtyStubDetector)->isAgentContext())->toBeFalse();
});

test('result is cached for the whole lifecycle', function () {
    putenv('CLAUDECODE=1');
    $detector = new TtyStubDetector;

    expect($detector->isAgentContext())->toBeTrue();

    // Env changes after first verdict must not matter until clearCache().
    putenv('CLAUDECODE');

    expect($detector->isAgentContext())->toBeTrue();

    TtyStubDetector::clearCache();
    TtyStubDetector::$tty = true;

    expect($detector->isAgentContext())->toBeFalse();
});

test('extra env vars can be added via config', function () {
    config()->set('agentlens.detect.env_vars', ['MY_CUSTOM_AGENT']);
    putenv('MY_CUSTOM_AGENT=yes');

    expect((new TtyStubDetector)->isAgentContext())->toBeTrue();
});
