<?php

declare(strict_types=1);

use Spora\Plugins\Social\Aggregator\Zernio\ZernioTransportException;
use Spora\Plugins\Social\Tests\Support\ZernioRecorder;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The aggregator's own client.
 *
 * The mocked responses are the recorded ones — the files under
 * `tests/Fixtures/Zernio/` — so a real run of `bin/zernio-probe` can
 * replace them without touching a test.
 */
it('sends the idempotency key on a publish', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('create-post'), 'status' => 201],
    ]);

    zernioClient($recorder->client())->createPost('the-key', [
        'platforms'  => [['platform' => 'bluesky', 'accountId' => 'id_001']],
        'content'    => 'We shipped…',
        'publishNow' => true,
    ], 'spora-key-1');

    expect($recorder->requests)->toHaveCount(1)
        ->and($recorder->method())->toBe('POST')
        ->and($recorder->url())->toBe('https://zernio.com/api/v1/posts');

    // The key is the layer that protects a retry whose body differs,
    // which is exactly what happens when the media URL is re-minted.
    expect($recorder->headers())->toHaveKey('idempotency-key')
        ->and($recorder->headers()['idempotency-key'])->toBe('spora-key-1')
        ->and($recorder->headers()['authorization'])->toBe('Bearer the-key');
});

it('uploads the bytes with a put and no authorization header', function () {
    $recorder = new ZernioRecorder();

    zernioClient($recorder->client())->upload('https://storage.zernio.com/redacted', 'the-bytes', 'image/png');

    expect($recorder->requests)->toHaveCount(1)
        ->and($recorder->method())->toBe('PUT')
        ->and($recorder->url())->toBe('https://storage.zernio.com/redacted')
        ->and($recorder->requests[0]['options']['body'])->toBe('the-bytes');

    // The presigned URL points at the storage bucket, not at the API.
    // A bearer token there is a credential sent to a third party.
    expect($recorder->headers())->not->toHaveKey('authorization')
        ->and($recorder->headers()['content-type'])->toBe('image/png');
});

it('answers a refusal with a value and a dead line with an exception', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('duplicate-post'), 'status' => 409],
    ]);

    $response = zernioClient($recorder->client())->createPost('the-key', [], 'spora-key-1');

    // A refusal is a normal outcome the publisher has to read.
    expect($response->isSuccessful())->toBeFalse()
        ->and($response->status)->toBe(409)
        ->and($response->errorMessage())->toContain('already scheduled')
        ->and($response->errorCode())->toBe('duplicate_content');

    $dead = new MockHttpClient(static function (): MockResponse {
        throw new TransportException('Connection refused');
    });

    // A call that never landed is a different thing: the publish may
    // still have happened, so it cannot fail the target.
    expect(fn() => zernioClient($dead)->createPost('the-key', [], 'spora-key-1'))
        ->toThrow(ZernioTransportException::class, 'Zernio did not answer');
});

it('asks for the accounts with the key', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts')],
    ]);

    zernioClient($recorder->client())->accounts('the-key', ['category' => 'social']);

    expect($recorder->url())->toBe('https://zernio.com/api/v1/accounts?category=social')
        ->and($recorder->headers()['authorization'])->toBe('Bearer the-key');
});
