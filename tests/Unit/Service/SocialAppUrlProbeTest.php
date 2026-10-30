<?php

declare(strict_types=1);

use Spora\Plugins\Social\Service\SocialAppUrlProbe;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Whether the install's public URL is reachable from the internet.
 */

/**
 * A probe whose answers the test controls.
 *
 * @param list<array{body: string, status?: int}> $responses
 */
function aProbe(array $responses = [['body' => '']]): SocialAppUrlProbe
{
    return new SocialAppUrlProbe(new MockHttpClient(array_map(
        static fn(array $response): MockResponse => new MockResponse(
            $response['body'],
            ['http_code' => $response['status'] ?? 200],
        ),
        $responses,
    )));
}

it('says that an address nobody gave is not reachable', function () {
    $probe = aProbe();

    expect($probe->isPubliclyReachable(null))->toBeFalse()
        ->and($probe->isPubliclyReachable('   '))->toBeFalse();
});

it('says that the loopback address is not reachable from the internet', function () {
    $probe = aProbe();

    expect($probe->isPubliclyReachable('http://localhost'))->toBeFalse()
        ->and($probe->isPubliclyReachable('http://app.localhost'))->toBeFalse();
});

it('says that a private address is not reachable from the internet', function () {
    $probe = aProbe();

    expect($probe->isPubliclyReachable('http://192.168.1.10'))->toBeFalse()
        ->and($probe->isPubliclyReachable('http://10.0.0.1'))->toBeFalse();
});

it('says that an address without a host is not reachable', function () {
    $probe = aProbe();

    expect($probe->isPubliclyReachable('not-an-address'))->toBeFalse();
});

it('says that a public address the host answers on is reachable', function () {
    $probe = aProbe();

    expect($probe->isPubliclyReachable(SocialFixtures::APP_URL))->toBeTrue();
});

it('says that a public address the host does not answer on is not reachable', function () {
    $probe = new SocialAppUrlProbe(new MockHttpClient(static function (): never {
        throw new TransportException('the host is down');
    }));

    expect($probe->isPubliclyReachable(SocialFixtures::APP_URL))->toBeFalse();
});

it('asks the host only once for a while', function () {
    $calls = 0;

    $client = new MockHttpClient(static function () use (&$calls): MockResponse {
        $calls++;

        return new MockResponse('');
    });

    $probe = new SocialAppUrlProbe($client);

    expect($probe->isPubliclyReachable(SocialFixtures::APP_URL))->toBeTrue()
        ->and($probe->isPubliclyReachable(SocialFixtures::APP_URL))->toBeTrue()
        ->and($calls)->toBe(1);
});
