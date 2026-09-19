<?php

use Agentlens\Formatting\TraceTrimmer;

function syntheticTrace(int $appFrames, int $vendorFrames): array
{
    $trace = [];
    for ($i = 0; $i < $appFrames; $i++) {
        $trace[] = [
            'class' => 'App\\Http\\Controllers\\OrderController',
            'type' => '::',
            'function' => "store{$i}",
            'file' => '/app/app/Http/Controllers/OrderController.php',
            'line' => 40 + $i,
        ];
    }
    for ($i = 0; $i < $vendorFrames; $i++) {
        $trace[] = [
            'class' => 'Illuminate\\Routing\\Router',
            'type' => '->',
            'function' => 'dispatch',
            'file' => '/app/vendor/laravel/framework/src/Illuminate/Routing/Router.php',
            'line' => 100 + $i,
        ];
    }

    return $trace;
}

test('trims to max frames and collapses vendor frames into one marker', function () {
    $trimmer = new TraceTrimmer(maxFrames: 3, skipVendorFrames: true, basePath: '/app');

    $top = $trimmer->trim(syntheticTrace(20, 10));

    expect($top)->toHaveCount(4)
        ->and($top[0])->toBe('App\Http\Controllers\OrderController::store0 (app/Http/Controllers/OrderController.php:40)')
        ->and($top[3])->toBe('(vendor skipped: 10 frames)');
});

test('30-frame trace never exceeds max frames plus marker', function () {
    $trimmer = new TraceTrimmer(maxFrames: 3, skipVendorFrames: true, basePath: '/app');

    $top = $trimmer->trim(syntheticTrace(15, 15));

    expect($top)->toHaveCount(4);
});

test('vendor frames are kept when skipping is disabled', function () {
    $trimmer = new TraceTrimmer(maxFrames: 3, skipVendorFrames: false, basePath: '/app');

    $top = $trimmer->trim(syntheticTrace(1, 5));

    expect($top)->toHaveCount(3)
        ->and($top)->each->not->toContain('vendor skipped');
});

test('frames without file info still format', function () {
    $trimmer = new TraceTrimmer;

    $top = $trimmer->trim([['function' => '{main}']]);

    expect($top)->toBe(['{main}']);
});

test('windows-style vendor paths are detected', function () {
    $trimmer = new TraceTrimmer(maxFrames: 5, skipVendorFrames: true, basePath: 'C:/app');

    $top = $trimmer->trim([
        ['class' => 'App\\Ctrl', 'type' => '::', 'function' => 'run', 'file' => 'C:\\app\\app\\Ctrl.php', 'line' => 1],
        ['class' => 'Illuminate\\Router', 'type' => '->', 'function' => 'go', 'file' => 'C:\\app\\vendor\\laravel\\Router.php', 'line' => 9],
    ]);

    expect($top)->toHaveCount(2)
        ->and($top[1])->toBe('(vendor skipped: 1 frames)');
});
