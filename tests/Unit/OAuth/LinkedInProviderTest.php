<?php

declare(strict_types=1);

use Spora\Plugins\Social\OAuth\LinkedInProvider;
use Spora\Plugins\Social\OAuth\OAuthTokenSet;
use Spora\Plugins\Social\Tests\Support\GuzzleRecorder;

it('builds the authorization url with a pkce challenge and the signed state', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', 'https://spora.test/callback', false, $recorder->client());

    $verifier = str_repeat('a', 64);
    $url = $provider->authorizationUrl('signed-state', $verifier);

    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(parse_url($url, PHP_URL_HOST))->toBe('www.linkedin.com')
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/oauth/v2/authorization')
        ->and($query['client_id'])->toBe('client-id')
        ->and($query['redirect_uri'])->toBe('https://spora.test/callback')
        ->and($query['state'])->toBe('signed-state')
        ->and($query['response_type'])->toBe('code')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))
        ->and($query['scope'])->toBe('openid profile email w_member_social');
});

it('asks for the organisation scope only when the operator says the app has it', function () {
    $recorder = new GuzzleRecorder();

    $withoutScope = new LinkedInProvider('id', 'secret', 'https://spora.test/cb', false, $recorder->client());
    parse_str((string) parse_url($withoutScope->authorizationUrl('s', str_repeat('a', 64)), PHP_URL_QUERY), $plainQuery);

    $withScope = new LinkedInProvider('id', 'secret', 'https://spora.test/cb', true, $recorder->client());
    parse_str((string) parse_url($withScope->authorizationUrl('s', str_repeat('a', 64)), PHP_URL_QUERY), $orgQuery);

    // The Posts API's own organisation permission, not the legacy
    // organisation-authorisation scope.
    //
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
    expect($plainQuery['scope'])->not->toContain('w_organization_social')
        ->and($plainQuery['scope'])->not->toContain('urn:li:organization:authorize')
        ->and($orgQuery['scope'])->toContain('w_organization_social')
        ->and($orgQuery['scope'])->not->toContain('urn:li:organization:authorize');
});

it('exchanges the code for tokens, carrying the pkce verifier', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider(
        'client-id',
        'client-secret',
        'https://spora.test/callback',
        false,
        $recorder->client([['body' => '{"access_token":"at","expires_in":5184000,"refresh_token":"rt","scope":"openid profile w_member_social"}']]),
    );

    $tokens = $provider->exchangeCode('the-code', str_repeat('v', 64));

    expect($tokens->accessToken)->toBe('at')
        ->and($tokens->refreshToken)->toBe('rt')
        ->and($tokens->expiresAt?->getTimestamp())->toBe(time() + 5184000)
        ->and($tokens->scopes)->toBe(['openid', 'profile', 'w_member_social']);

    expect($recorder->requests)->toHaveCount(1);

    $request = $recorder->requests[0]['request'];
    $fields = formOf([
        'body' => (string) $request->getBody(),
    ]);

    expect((string) $request->getUri())->toBe('https://www.linkedin.com/oauth/v2/accessToken')
        ->and($fields['grant_type'])->toBe('authorization_code')
        ->and($fields['code'])->toBe('the-code')
        ->and($fields['code_verifier'])->toBe(str_repeat('v', 64))
        ->and($fields['client_id'])->toBe('client-id')
        ->and($fields['client_secret'])->toBe('client-secret')
        ->and($fields['redirect_uri'])->toBe('https://spora.test/callback');
});

it('refreshes without a pkce verifier, which a refresh is not', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider(
        'client-id',
        'client-secret',
        'https://spora.test/callback',
        false,
        $recorder->client([['body' => '{"access_token":"at2","expires_in":5184000,"refresh_token":"rt2"}']]),
    );

    $tokens = $provider->refresh(new OAuthTokenSet(
        accessToken: 'at',
        refreshToken: 'rt',
    ));

    expect($tokens->accessToken)->toBe('at2')
        ->and($tokens->refreshToken)->toBe('rt2');

    $request = $recorder->requests[0]['request'];
    $fields = formOf(['body' => (string) $request->getBody()]);

    expect($fields['grant_type'])->toBe('refresh_token')
        ->and($fields['refresh_token'])->toBe('rt')
        ->and($fields)->not->toHaveKey('code_verifier');
});

it('refuses to refresh without a refresh token', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('id', 'secret', 'https://spora.test/cb', false, $recorder->client());

    expect(fn() => $provider->refresh(new OAuthTokenSet(accessToken: 'at')))
        ->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'no refresh token');
});

it('resolves the member account and its reactions-only note', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider(
        'client-id',
        'client-secret',
        'https://spora.test/callback',
        false,
        $recorder->client([['body' => '{"sub":"abc123","name":"Fabeat Person","email":"fabeat@example.test"}']]),
    );

    $accounts = $provider->resolveAccounts(new OAuthTokenSet(accessToken: 'at'));

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->externalId)->toBe('urn:li:person:abc123')
        ->and($accounts[0]->kind->value)->toBe('member')
        ->and($accounts[0]->displayName)->toBe('Fabeat Person');

    $request = $recorder->requests[0]['request'];
    expect((string) $request->getUri())->toBe('https://api.linkedin.com/v2/userinfo')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer at');
});
