<?php

/*
 * Micro-benchmarks for agentlens hot paths. No framework boot required:
 * everything measured here is pure PHP (+ Monolog value objects).
 *
 * Run: php benchmarks/bench.php
 */

use Agentlens\Dedupe\ArrayDedupeStore;
use Agentlens\Dedupe\FingerprintGenerator;
use Agentlens\Dedupe\RegexMessageNormalizer;
use Agentlens\Detection\EnvAndTtyAgentDetector;
use Agentlens\Formatting\CompactJsonFormatter;
use Agentlens\Formatting\LogRecordDTO;
use Agentlens\Formatting\TraceTrimmer;

require __DIR__.'/../vendor/autoload.php';

function measure(string $label, int $iterations, Closure $fn): array
{
    // Warmup.
    for ($i = 0; $i < min(1000, $iterations); $i++) {
        $fn($i);
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn($i);
    }
    $elapsedNs = hrtime(true) - $start;

    $perOpNs = $elapsedNs / $iterations;
    printf("%-38s %10d ops %12.2f ms total %10.1f ns/op\n", $label, $iterations, $elapsedNs / 1e6, $perOpNs);

    return [$label, $iterations, $elapsedNs / 1e6, $perOpNs];
}

function fakeTrace(int $frames): array
{
    $trace = [];
    for ($i = 0; $i < $frames; $i++) {
        $vendor = $i % 3 === 2;
        $trace[] = [
            'class' => $vendor ? 'Illuminate\\Routing\\Router' : 'App\\Http\\Controllers\\OrderController',
            'type' => $vendor ? '->' : '::',
            'function' => $vendor ? 'dispatch' : 'store',
            'file' => $vendor
                ? '/app/vendor/laravel/framework/src/Illuminate/Routing/Router.php'
                : '/app/app/Http/Controllers/OrderController.php',
            'line' => 40 + $i,
        ];
    }

    return $trace;
}

echo "== agentlens micro-benchmarks (".PHP_VERSION.") ==\n\n";

// 1. Cached agent detection (the only thing that runs in human mode).
putenv('CLAUDECODE=1');
$detector = new EnvAndTtyAgentDetector;
measure('detector (cached verdict)', 100_000, fn () => $detector->isAgentContext());

// 2. Message normalization + fingerprinting.
$generator = new FingerprintGenerator(new RegexMessageNormalizer);
measure('fingerprint (normalize+sha1)', 20_000, fn ($i) => $generator->forRecord(
    new LogRecordDTO(level: 'error', message: "Order ".(881 + ($i % 500))." not found")
));

// 3. Full compact formatting of an exception record.
$formatter = new CompactJsonFormatter(new TraceTrimmer(3, true, '/app'));
$dto = new LogRecordDTO(
    level: 'error',
    message: 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry',
    exceptionClass: 'Illuminate\\Database\\QueryException',
    file: '/app/app/Http/Controllers/OrderController.php',
    line: 42,
    trace: fakeTrace(30),
    context: ['order_id' => 881],
    sql: 'insert into `orders` (...) values (...)',
);
measure('compact format (30-frame exception)', 5_000, fn () => $formatter->format($dto));

// 4. Dedupe gate: 50k suppressed repeats.
$store = new ArrayDedupeStore;
$fp = str_repeat('a', 40);
measure('dedupe gate (shouldEmit+incr)', 50_000, function () use ($store, $fp) {
    if ($store->shouldEmit($fp, 60)) {
        // first only
    }
    $store->incrementAndGetCount($fp);
});

echo "\n== output size: 50x repeated QueryException (30 frames) ==\n\n";

// "Before": what Laravel's `single` channel writes per occurrence —
// timestamped line + full multi-line stack trace (realistic rendering).
$traditionalOne = "[2026-09-19 12:00:00] production.ERROR: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry {\"order_id\":881,\"exception\":\"[object] (Illuminate\\\\Database\\\\QueryException(code: 23000): SQLSTATE[23000] at /app/app/Http/Controllers/OrderController.php:42)\n";
foreach (fakeTrace(30) as $i => $f) {
    $traditionalOne .= "#{$i} {$f['file']}({$f['line']}): {$f['class']}{$f['type']}{$f['function']}()\n";
}
$traditionalOne .= "\"} \n";
$beforeBytes = strlen($traditionalOne) * 50;

// "After": end-to-end through the real handler — header legend + 1 full
// compact line + 1 summary line, exactly as it lands in agentlens.log.
function throwBenchDeep(int $depth): void
{
    if ($depth <= 0) {
        throw new RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
    }
    throwBenchDeep($depth - 1);
}

$benchFile = sys_get_temp_dir().'/agentlens.log';
@unlink($benchFile);

$benchHandler = new Agentlens\Logging\AgentlensHandler(
    new FingerprintGenerator(new RegexMessageNormalizer),
    $formatter,
    new ArrayDedupeStore,
    null,
);

try {
    throwBenchDeep(30);
} catch (RuntimeException $benchException) {
    $benchRecord = new Monolog\LogRecord(
        new DateTimeImmutable,
        'bench',
        Monolog\Level::Error,
        $benchException->getMessage(),
        ['exception' => $benchException, 'order_id' => 881],
        []
    );

    for ($i = 0; $i < 50; $i++) {
        $benchHandler->handle($benchRecord);
    }
    $benchHandler->flushSummaries();
}

$afterBytes = file_exists($benchFile) ? filesize($benchFile) : 0;

foreach (array_filter(array_map('trim', file($benchFile) ?: [])) as $n => $benchLine) {
    printf("after line %d bytes: %d\n", $n, strlen($benchLine));
}

$benchHandler->close();
@unlink($benchFile);

$tokens = fn ($bytes) => (int) round($bytes / 4);
$reduction = 100 * (1 - $afterBytes / $beforeBytes);

printf("before (50x full trace) : %9d bytes  ~%7d tokens\n", $beforeBytes, $tokens($beforeBytes));
printf("after  (file on disk)   : %9d bytes  ~%7d tokens\n", $afterBytes, $tokens($afterBytes));
printf("reduction               : %.1f%%\n", $reduction);
printf("(after = header legend + 1 full line + 1 summary line)\n");

echo "\n== output size: single error / two distinct errors (no dedupe) ==\n\n";

// Two distinct throw sites (different file:line => different fingerprints).
function throwBenchOther(int $depth): void
{
    if ($depth <= 0) {
        throw new RuntimeException('Order 882 not found');
    }
    throwBenchOther($depth - 1);
}

@unlink($benchFile);

$benchHandler2 = new Agentlens\Logging\AgentlensHandler(
    new FingerprintGenerator(new RegexMessageNormalizer),
    $formatter,
    new ArrayDedupeStore,
    null,
);

try {
    throwBenchDeep(5);
} catch (RuntimeException $first) {
    $benchHandler2->handle(new Monolog\LogRecord(
        new DateTimeImmutable, 'bench', Monolog\Level::Error,
        $first->getMessage(), ['exception' => $first], []
    ));
}

$singleAfter = file_exists($benchFile) ? filesize($benchFile) : 0;

try {
    throwBenchOther(5);
} catch (RuntimeException $second) {
    $benchHandler2->handle(new Monolog\LogRecord(
        new DateTimeImmutable, 'bench', Monolog\Level::Error,
        $second->getMessage(), ['exception' => $second], []
    ));
}

$twoAfter = file_exists($benchFile) ? filesize($benchFile) : 0;
$benchHandler2->close();
@unlink($benchFile);

// "Before" for the same scenario: one / two full traditional traces.
$singleBefore = strlen($traditionalOne);
$twoBefore = $singleBefore * 2;

printf("single error : before %9d bytes (~%6d tokens) -> after %9d bytes (~%6d tokens)  reduction %.1f%%\n",
    $singleBefore, $tokens($singleBefore), $singleAfter, $tokens($singleAfter),
    100 * (1 - $singleAfter / $singleBefore));
printf("two distinct : before %9d bytes (~%6d tokens) -> after %9d bytes (~%6d tokens)  reduction %.1f%%\n",
    $twoBefore, $tokens($twoBefore), $twoAfter, $tokens($twoAfter),
    100 * (1 - $twoAfter / $twoBefore));
printf("(after = header legend + full line(s); no summaries — nothing repeats)\n");
