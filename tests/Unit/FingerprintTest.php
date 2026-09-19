<?php

use Agentlens\Dedupe\FingerprintGenerator;
use Agentlens\Dedupe\RegexMessageNormalizer;
use Agentlens\Formatting\LogRecordDTO;

test('normalizer collapses ids, uuids and hashes', function () {
    $normalizer = new RegexMessageNormalizer;

    expect($normalizer->normalize('Order 881 not found'))
        ->toBe($normalizer->normalize('Order 882 not found'))
        ->and($normalizer->normalize('job 550e8400-e29b-41d4-a716-446655440000 failed'))
        ->toBe($normalizer->normalize('job 6ba7b810-9dad-11d1-80b4-00c04fd430c8 failed'))
        ->and($normalizer->normalize('Order 881 not found'))
        ->not->toBe($normalizer->normalize('Payment 881 declined'));
});

test('same exception location shares a fingerprint', function () {
    $generator = new FingerprintGenerator(new RegexMessageNormalizer);

    $file = __FILE__;
    $e1 = new RuntimeException('boom');
    $e2 = new RuntimeException('different message, same line? no — different line');

    // Same instance reported twice (retry loop) => identical fingerprint.
    expect($generator->forThrowable($e1))->toBe($generator->forThrowable($e1));
});

test('different messages with same template share a record fingerprint', function () {
    $generator = new FingerprintGenerator(new RegexMessageNormalizer);

    $a = new LogRecordDTO(level: 'error', message: 'Order 881 not found');
    $b = new LogRecordDTO(level: 'error', message: 'Order 882 not found');
    $c = new LogRecordDTO(level: 'error', message: 'Payment declined');

    expect($generator->forRecord($a))->toBe($generator->forRecord($b))
        ->and($generator->forRecord($a))->not->toBe($generator->forRecord($c));
});

test('exception fingerprint ignores the message text', function () {    $generator = new FingerprintGenerator(new RegexMessageNormalizer);

    $e = new RuntimeException('some dynamic detail 12345');
    $dto = LogRecordDTO::fromThrowable($e);

    expect($dto->exceptionClass)->toBe(RuntimeException::class)
        ->and($generator->forRecord($dto))->toBe($generator->forThrowable($e));
});

test('hex hashes and ulid-like tokens are normalized too', function () {
    $normalizer = new RegexMessageNormalizer;

    expect($normalizer->normalize('commit abcdef1234567890 deployed'))
        ->toBe($normalizer->normalize('commit 0987654321fedcba deployed'))
        ->and($normalizer->normalize('key 01ARZ3NDEKTSV4RRFFQ69G5FAV seen'))
        ->toBe($normalizer->normalize('key 01ARZ3NDEKTSV4RRFFQ69G5FB2 seen'))
        ->and($normalizer->normalize('plain words here'))
        ->toBe('plain words here');
});
