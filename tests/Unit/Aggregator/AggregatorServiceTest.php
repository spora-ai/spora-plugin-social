<?php

declare(strict_types=1);

use Spora\Core\ValueObjects\EncryptedValue;
use Spora\Plugins\Social\Aggregator\AggregatorProvider;
use Spora\Plugins\Social\Aggregator\AggregatorService;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Service\SocialConnectionTokens;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Tests\Support\FakeOAuthProvider;
use Spora\Plugins\Social\Tests\Support\FakeProviderResolver;
use Spora\Plugins\Social\Tests\Support\ZernioRecorder;

/**
 * The aggregator's own service, against a mocked Zernio.
 */
function aggregatorServiceWith(ZernioRecorder $recorder): AggregatorService
{
    return new AggregatorService(
        zernioClient($recorder->client()),
        new SocialConnectionTokens(
            new SocialSettings(socialConfig()),
            new FakeProviderResolver([]),
            securityManager(),
        ),
    );
}

it('stores the key on the connection, encrypted, with no expiry and no refresh grant', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = aggregatorServiceWith($recorder)->setKey($principalId, AggregatorProvider::Zernio, 'the-key');

    expect($connection->provider)->toBe('zernio')
        ->and($connection->expires_at)->toBeNull()
        ->and($connection->refresh_token)->toBeNull()
        ->and($connection->scopes)->toBeNull();

    // The key is a credential: it is held the way the OAuth tokens are.
    expect(securityManager()->decrypt(new EncryptedValue((string) $connection->access_token)))
        ->toBe('the-key');
});

it('refuses a key Zernio refuses', function () {
    $recorder = new ZernioRecorder([
        ['body' => '{"error":"Invalid API key","code":"invalid_credentials"}', 'status' => 401],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    expect(fn() => aggregatorServiceWith($recorder)->setKey($principalId, AggregatorProvider::Zernio, 'wrong-key'))
        ->toThrow(SocialException::class, 'Zernio refused that API key');
});

it('answers the health with a cheap authenticated call and never resolves accounts', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts')],
    ]);

    $provider = new FakeOAuthProvider(SocialProvider::LinkedIn);
    $resolver = new FakeProviderResolver(['linkedin' => $provider]);

    $service = connectionService($resolver, null, [], aggregatorServiceWith($recorder));

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'zernio', ['access_token' => 'the-key']);

    $health = $service->health((int) $connection->id, $principalId);

    expect($health['status'])->toBe(SocialConnection::STATUS_ACTIVE)
        ->and($provider->resolveCalls)->toBe(0)
        ->and($recorder->url())->toBe('https://zernio.com/api/v1/accounts?limit=1')
        ->and($recorder->headers()['authorization'])->toBe('Bearer the-key');
});

it('does not claim a refresh when there is no refresh grant', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'zernio', ['access_token' => 'the-key']);

    $service = connectionService();

    // The key has no grant: the connection comes back as it was, and the
    // message says the key is replaced rather than renewed.
    expect($service->refresh((int) $connection->id, $principalId)->id)->toBe($connection->id);

    $tool = new Spora\Plugins\Social\Tools\SocialConnectionsTool($service);

    $result = $tool->execute(
        ['action' => 'refresh', 'connection_id' => (int) $connection->id],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('no refresh grant')
        ->and($result->content)->not->toContain('Refreshed');
});

it('forgets the key and says the accounts stay in the aggregator', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'zernio', ['access_token' => 'the-key']);

    $account = createSocialAccount($principalId, 'bluesky', 'aggregator', [
        'connection' => $connection,
        'external_id' => 'id_001',
        'via'        => 'zernio',
    ]);

    $service = connectionService();

    $service->disconnect((int) $connection->id, $principalId);

    expect(SocialConnection::query()->find($connection->id))->toBeNull();

    // The account is gone from the plugin's reach, not from Zernio: a
    // post's history keeps its target.
    expect($account->fresh()?->status)->toBe('disconnected');
});

it('never points the OAuth connect flow at an aggregator', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $auth = bootAuthLayer();

    $controller = new Spora\Plugins\Social\Http\SocialConnectionController(
        $auth,
        new Spora\Services\PrincipalResolver(),
        new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver()),
        connectionService(),
        new SocialSettings(socialConfig()),
        new Spora\Plugins\Social\Service\SocialAppUrlProbe(
            new Symfony\Component\HttpClient\MockHttpClient([
                new Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 200]),
            ]),
        ),
    );

    $response = $controller->connect(jsonRequest('POST', '/api/v1/plugins/social/connections/connect', [
        'principal_id' => $principalId,
        'provider'     => 'zernio',
    ]));

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode((string) $response->getContent(), true)['error']['message'])
        ->toContain('provider must be meta or linkedin');
});
