<?php

declare(strict_types=1);

use Spora\Plugins\Social\OAuth\MetaProvider;
use Spora\Plugins\Social\OAuth\OAuthTokenSet;
use Spora\Plugins\Social\Tests\Support\GuzzleRecorder;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;

/**
 * The Meta login, transcribed from the documentation.
 *
 * @see https://developers.facebook.com/documentation/facebook-login/facebook-login-for-business
 * @see https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-unified-login/get-started
 */
it('builds the login url of the configuration', function () {
    $recorder = new GuzzleRecorder();
    $provider = new MetaProvider(
        'client-id',
        'client-secret',
        SocialFixtures::OAUTH_CALLBACK,
        'config-id',
        null,
        $recorder->client(),
    );

    $url = $provider->authorizationUrl('signed-state', str_repeat('a', 64));

    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    // "https://www.facebook.com/{version}/dialog/oauth?client_id=…&config_id=…
    //  &redirect_uri=…&response_type=code"
    expect(parse_url($url, PHP_URL_HOST))->toBe('www.facebook.com')
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/v26.0/dialog/oauth')
        ->and($query['client_id'])->toBe('client-id')
        ->and($query['config_id'])->toBe('config-id')
        ->and($query['redirect_uri'])->toBe(SocialFixtures::OAUTH_CALLBACK)
        ->and($query['response_type'])->toBe('code')
        ->and($query['state'])->toBe('signed-state')
        // The configuration carries the permissions; a scope list would be
        // ignored.
        ->and($query)->not->toHaveKey('scope');
});

it('exchanges the code server to server and stores the client business id', function () {
    $recorder = new GuzzleRecorder();
    $provider = new MetaProvider(
        'client-id',
        'client-secret',
        SocialFixtures::OAUTH_CALLBACK,
        'config-id',
        null,
        $recorder->client([
            ['body' => '{"access_token":"the-suat"}'],
            ['body' => '{"client_business_id":"987654321","id":"1234567890"}'],
        ]),
    );

    $tokens = $provider->exchangeCode('the-code', str_repeat('v', 64));

    expect($tokens->accessToken)->toBe('the-suat')
        // A system-user token "defaults to never expire", so there is no
        // expiry to store.
        ->and($tokens->expiresAt)->toBeNull()
        ->and($tokens->extra['business_external_id'])->toBe(SocialFixtures::META_MEMBER_ID);

    // "GET https://graph.facebook.com/{version}/oauth/access_token?
    //  client_id=…&client_secret=…&code=…"
    $exchange = $recorder->requests[0]['request'];

    expect((string) $exchange->getUri())->toStartWith('https://graph.facebook.com/v26.0/oauth/access_token?')
        ->and((string) $exchange->getUri())->toContain('client_id=client-id')
        ->and((string) $exchange->getUri())->toContain('client_secret=client-secret')
        ->and((string) $exchange->getUri())->toContain('code=the-code');

    // "GET /me?fields=client_business_id"
    expect((string) $recorder->requests[1]['request']->getUri())
        ->toStartWith('https://graph.facebook.com/v26.0/me?')
        ->and((string) $recorder->requests[1]['request']->getUri())->toContain('fields=client_business_id');
});

it('records the scopes the token response names', function () {
    $recorder = new GuzzleRecorder();
    $provider = new MetaProvider(
        'client-id',
        'client-secret',
        SocialFixtures::OAUTH_CALLBACK,
        'config-id',
        null,
        $recorder->client([
            ['body' => '{"access_token":"the-suat","scope":"instagram_basic,instagram_content_publish"}'],
            ['body' => '{"client_business_id":"987654321"}'],
        ]),
    );

    $tokens = $provider->exchangeCode('the-code', str_repeat('v', 64));

    // The scopes are what the connection records; without them the plugin
    // cannot say which permissions the token carries.
    expect($tokens->scopes)->toBe(['instagram_basic', 'instagram_content_publish']);
});

it('leaves the scopes unknown when the token response names none', function () {
    $recorder = new GuzzleRecorder();
    $provider = new MetaProvider(
        'client-id',
        'client-secret',
        SocialFixtures::OAUTH_CALLBACK,
        'config-id',
        null,
        $recorder->client([
            ['body' => '{"access_token":"the-suat"}'],
            ['body' => '{"client_business_id":"987654321"}'],
        ]),
    );

    $tokens = $provider->exchangeCode('the-code', str_repeat('v', 64));

    // Unknown is not "not granted": a Meta system-user token reports its
    // permissions through the configuration, not through the response.
    expect($tokens->scopes)->toBe([]);
});

it('renews nothing, because the token does not expire', function () {
    $recorder = new GuzzleRecorder();
    $provider = new MetaProvider(
        'client-id',
        'client-secret',
        SocialFixtures::OAUTH_CALLBACK,
        'config-id',
        null,
        $recorder->client(),
    );

    $tokens = new OAuthTokenSet(accessToken: 'the-suat');
    $refreshed = $provider->refresh($tokens);

    expect($refreshed->accessToken)->toBe('the-suat')
        ->and($recorder->requests)->toHaveCount(0);
});

it('resolves the instagram accounts and the pages the client shared', function () {
    $recorder = new GuzzleRecorder();
    $provider = new MetaProvider(
        'client-id',
        'client-secret',
        SocialFixtures::OAUTH_CALLBACK,
        'config-id',
        null,
        $recorder->client([
            [
                'body' => '{"data":[{"id":"17841405822304914","username":"fabeat"}],"paging":{"cursors":{}}}',
            ],
            [
                'body' => '{"data":[{"id":"111222333","name":"Fabeat GmbH","username":"fabeatgmbh"}],"paging":{}}',
            ],
        ]),
    );

    $accounts = $provider->resolveAccounts(new OAuthTokenSet(
        accessToken: 'the-suat',
        extra: ['business_external_id' => SocialFixtures::META_MEMBER_ID],
    ));

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->platform->value)->toBe('instagram')
        ->and($accounts[0]->externalId)->toBe('17841405822304914')
        ->and($accounts[0]->kind->value)->toBe('business')
        ->and($accounts[0]->username)->toBe('fabeat')
        ->and($accounts[0]->extra['ig_user_id'])->toBe('17841405822304914')
        ->and($accounts[1]->platform->value)->toBe('facebook')
        ->and($accounts[1]->externalId)->toBe('111222333')
        ->and($accounts[1]->kind->value)->toBe('page')
        ->and($accounts[1]->displayName)->toBe('Fabeat GmbH');

    // "GET /me/assigned_instagram_accounts?fields=id,username"
    expect((string) $recorder->requests[0]['request']->getUri())
        ->toStartWith('https://graph.facebook.com/v26.0/me/assigned_instagram_accounts?')
        ->and((string) $recorder->requests[0]['request']->getUri())->toContain('fields=id%2Cusername');

    // "GET /{client business id}/owned_pages"
    expect((string) $recorder->requests[1]['request']->getUri())
        ->toStartWith('https://graph.facebook.com/v26.0/987654321/owned_pages');
});

it('leaves the pages out when the token cannot list them', function () {
    $recorder = new GuzzleRecorder();
    $provider = new MetaProvider(
        'client-id',
        'client-secret',
        SocialFixtures::OAUTH_CALLBACK,
        'config-id',
        null,
        $recorder->client([
            [
                'body' => '{"data":[{"id":"17841405822304914","username":"fabeat"}],"paging":{"cursors":{}}}',
            ],
            [
                'body' => '{"error":{"message":"(#10) Permission is not granted","type":"OAuthException"}}',
                'status' => 403,
            ],
        ]),
    );

    $accounts = $provider->resolveAccounts(new OAuthTokenSet(
        accessToken: 'the-suat',
        extra: ['business_external_id' => SocialFixtures::META_MEMBER_ID],
    ));

    // The pages need the `business_management` permission; without it the
    // connection covers the Instagram account alone.
    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->platform->value)->toBe('instagram');
});
