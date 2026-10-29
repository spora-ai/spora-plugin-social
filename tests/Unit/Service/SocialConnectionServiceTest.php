<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\OAuth\OAuthTokenSet;
use Spora\Plugins\Social\OAuth\SocialStateCodec;
use Spora\Plugins\Social\Service\SocialAppUrlProbe;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Plugins\Social\Service\SocialConnectionTokens;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Tests\Support\FakeOAuthProvider;
use Spora\Plugins\Social\Tests\Support\FakeProviderResolver;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

it('renews a token that is inside the ten-day window, before it expires', function () {
    $provider = new FakeOAuthProvider(SocialProvider::LinkedIn, refreshed: new OAuthTokenSet(
        accessToken: 'renewed-token',
        refreshToken: 'renewed-refresh',
        expiresAt: new DateTimeImmutable('+60 days'),
    ));

    $service = connectionService(new FakeProviderResolver(['linkedin' => $provider]));

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'linkedin', [
        'access_token'  => 'old-token',
        'refresh_token' => 'old-refresh',
        // Five days out is inside the window, so the token is renewed
        // before the next publish.
        'expires_at'    => new DateTimeImmutable('+5 days'),
    ]);

    $token = $service->accessToken($connection->fresh() ?? $connection);

    expect($provider->refreshCalls)->toBe(1)
        ->and($token)->toBe('renewed-token');

    $stored = $connection->fresh() ?? $connection;

    expect(securityManager()->decrypt(new Spora\Core\ValueObjects\EncryptedValue((string) $stored->access_token)))
        ->toBe('renewed-token');
});

it('leaves a token that is still fresh alone', function () {
    $provider = new FakeOAuthProvider(SocialProvider::LinkedIn);

    $service = connectionService(new FakeProviderResolver(['linkedin' => $provider]));

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'fresh-token',
        'expires_at'   => new DateTimeImmutable('+50 days'),
    ]);

    $token = $service->accessToken($connection->fresh() ?? $connection);

    expect($provider->refreshCalls)->toBe(0)
        ->and($token)->toBe('fresh-token');
});

it('renews a token that has already expired', function () {
    $provider = new FakeOAuthProvider(SocialProvider::LinkedIn);

    $service = connectionService(new FakeProviderResolver(['linkedin' => $provider]));

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'stale-token',
        'expires_at'   => new DateTimeImmutable('-1 day'),
    ]);

    $service->accessToken($connection->fresh() ?? $connection);

    expect($provider->refreshCalls)->toBe(1);
});

it('leaves a token that does not expire alone', function () {
    $provider = new FakeOAuthProvider(SocialProvider::Meta);

    $service = connectionService(new FakeProviderResolver(['meta' => $provider]));

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    // A system-user token has no expiry at all.
    $connection = createSocialConnection($principalId, 'meta', ['access_token' => 'the-suat']);

    $token = $service->accessToken($connection->fresh() ?? $connection);

    expect($provider->refreshCalls)->toBe(0)
        ->and($token)->toBe('the-suat');
});

it('publishes every account of a connection under the one token', function () {
    $provider = new FakeOAuthProvider(SocialProvider::Meta);

    $service = connectionService(new FakeProviderResolver(['meta' => $provider]));

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'meta', ['access_token' => 'the-suat']);

    $instagram = createSocialAccount($principalId, 'instagram', 'business', ['connection' => $connection]);
    $facebook = createSocialAccount($principalId, 'facebook', 'page', ['connection' => $connection]);

    expect($service->tokenFor($instagram->fresh() ?? $instagram))->toBe('the-suat')
        ->and($service->tokenFor($facebook->fresh() ?? $facebook))->toBe('the-suat');
});

it('stores one connection and the accounts it covers', function () {
    $provider = new FakeOAuthProvider(
        SocialProvider::Meta,
        accounts: [
            new Spora\Plugins\Social\OAuth\OAuthAccountIdentity(
                platform: Spora\Plugins\Social\Model\SocialPlatform::Instagram,
                externalId: '17841405822304914',
                kind: Spora\Plugins\Social\Model\SocialAccountKind::Business,
            ),
            new Spora\Plugins\Social\OAuth\OAuthAccountIdentity(
                platform: Spora\Plugins\Social\Model\SocialPlatform::Facebook,
                externalId: '111222333',
                kind: Spora\Plugins\Social\Model\SocialAccountKind::Page,
            ),
        ],
    );

    $service = connectionService(new FakeProviderResolver(['meta' => $provider]));

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $request = $service->startConnect($principalId, SocialProvider::Meta);
    $result = $service->completeConnect('the-code', $request['state']);

    // One login, one token, two networks.
    expect($result['accounts'])->toHaveCount(2);

    $connection = Spora\Plugins\Social\Model\SocialConnection::query()->first();

    expect($connection)->not->toBeNull()
        ->and($connection->provider)->toBe('meta')
        ->and($connection->config_id)->toBe('meta-config-id')
        ->and(Spora\Plugins\Social\Model\SocialAccount::query()->count())->toBe(2)
        ->and(Spora\Plugins\Social\Model\SocialAccount::query()->first()?->connection_id)
        ->toBe($connection?->id);
});

it('flags a connection whose token is about to expire', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $soon = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'the-token',
        'expires_at'   => new DateTimeImmutable('+5 days'),
    ]);

    $later = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'the-token',
        'expires_at'   => new DateTimeImmutable('+50 days'),
    ]);

    // A Meta token never expires, so it never needs a reconnect.
    $meta = createSocialConnection($principalId, 'meta', ['access_token' => 'the-suat']);

    expect(SocialConnectionService::needsReconnect($soon))->toBeTrue()
        ->and(SocialConnectionService::needsReconnect($later))->toBeFalse()
        ->and(SocialConnectionService::needsReconnect($meta))->toBeFalse();
});

it('flags a connection whose token has expired', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'the-token',
        'expires_at'   => new DateTimeImmutable('-1 day'),
    ]);

    expect(SocialConnectionService::statusOf($connection))->toBe('expired')
        ->and(SocialConnectionService::needsReconnect($connection))->toBeTrue();
});

it('remembers that the platform revoked the token', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $provider = new FakeOAuthProvider(
        SocialProvider::LinkedIn,
        refusal: 'Invalid OAuth 2.0 Access Token',
    );

    $service = connectionService(new FakeProviderResolver(['linkedin' => $provider]));

    $connection = createSocialConnection($principalId, 'linkedin', ['access_token' => 'the-token']);

    $health = $service->health((int) $connection->id, $principalId);

    // The platform naming the token itself is a different answer from
    // "the call failed": only the first one needs the client to connect
    // again.
    expect($health['status'])->toBe('revoked')
        ->and(SocialConnectionService::statusOf($connection->fresh() ?? $connection))->toBe('revoked')
        ->and(SocialConnectionService::needsReconnect($connection->fresh() ?? $connection))->toBeTrue();
});

it('remembers that a call failed for another reason', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $provider = new FakeOAuthProvider(
        SocialProvider::LinkedIn,
        refusal: 'Service unavailable',
    );

    $service = connectionService(new FakeProviderResolver(['linkedin' => $provider]));

    $connection = createSocialConnection($principalId, 'linkedin', ['access_token' => 'the-token']);

    $health = $service->health((int) $connection->id, $principalId);

    expect($health['status'])->toBe('error')
        ->and(SocialConnectionService::statusOf($connection->fresh() ?? $connection))->toBe('error');
});

it('refuses to connect when the app url is not publicly reachable', function () {
    $probe = new SocialAppUrlProbe(new MockHttpClient([
        new MockResponse('', ['error' => 'Connection refused']),
    ]));

    $settings = new SocialSettings(socialConfig());

    $service = new SocialConnectionService(
        $settings,
        new FakeProviderResolver([]),
        new SocialStateCodec(securityManager()),
        $probe,
        new SocialConnectionTokens($settings, new FakeProviderResolver([]), securityManager()),
    );

    expect(fn() => $service->startConnect(1, SocialProvider::Meta))
        ->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'publicly reachable');
});

it('refuses to connect Meta without a configuration id', function () {
    $settings = new SocialSettings(socialConfig([SocialSettings::KEY_META_CONFIG_ID => '']));

    $service = new SocialConnectionService(
        $settings,
        new FakeProviderResolver([]),
        new SocialStateCodec(securityManager()),
        new SocialAppUrlProbe(new MockHttpClient([new MockResponse('', ['http_code' => 200])])),
        new SocialConnectionTokens($settings, new FakeProviderResolver([]), securityManager()),
    );

    expect(fn() => $service->startConnect(1, SocialProvider::Meta))
        ->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'configuration id');
});

it('signs the state with the principal, the provider and a nonce', function () {
    $provider = new FakeOAuthProvider(SocialProvider::LinkedIn);

    $service = connectionService(new FakeProviderResolver(['linkedin' => $provider]));

    $request = $service->startConnect(7, SocialProvider::LinkedIn);

    $payload = (new SocialStateCodec(securityManager()))->decode($request['state']);

    expect($payload['principal_id'])->toBe(7)
        ->and($payload['provider'])->toBe('linkedin')
        ->and($payload['nonce'])->not->toBeEmpty()
        ->and($payload['pkce'])->not->toBeEmpty()
        ->and($request['authorize_url'])->toContain('state=');
});
