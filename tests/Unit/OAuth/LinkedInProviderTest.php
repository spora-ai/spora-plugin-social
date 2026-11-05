<?php

declare(strict_types=1);

use Spora\Plugins\Social\OAuth\LinkedInPermissions;
use Spora\Plugins\Social\OAuth\LinkedInProvider;
use Spora\Plugins\Social\OAuth\OAuthTokenSet;
use Spora\Plugins\Social\Tests\Support\GuzzleRecorder;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;

it('builds the authorization url without a pkce challenge', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(), $recorder->client());

    $url = $provider->authorizationUrl('signed-state');

    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(parse_url($url, PHP_URL_HOST))->toBe('www.linkedin.com')
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/oauth/v2/authorization')
        ->and($query['client_id'])->toBe('client-id')
        ->and($query['redirect_uri'])->toBe(SocialFixtures::OAUTH_CALLBACK)
        ->and($query['state'])->toBe('signed-state')
        ->and($query['response_type'])->toBe('code')
        ->and($query['scope'])->toBe('openid profile email w_member_social');

    // LinkedIn's token endpoint does not take a verifier: it answers the
    // exchange with "invalid_client — Client authentication failed" when
    // one is sent. A challenge is not offered either, because a code issued
    // against a challenge has nothing to verify a verifier against.
    expect($query)->not->toHaveKey('code_challenge')
        ->and($query)->not->toHaveKey('code_challenge_method');
});

it('asks for the organisation scope only when the operator says the app has it', function () {
    $recorder = new GuzzleRecorder();

    $withoutScope = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(), $recorder->client());
    parse_str((string) parse_url($withoutScope->authorizationUrl('s'), PHP_URL_QUERY), $plainQuery);

    $withScope = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(organizationScope: true), $recorder->client());
    parse_str((string) parse_url($withScope->authorizationUrl('s'), PHP_URL_QUERY), $orgQuery);

    // The Posts API's own organisation permission, not the legacy
    // organisation-authorisation scope.
    //
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
    expect($plainQuery['scope'])->not->toContain('w_organization_social')
        ->and($plainQuery['scope'])->not->toContain('urn:li:organization:authorize')
        ->and($orgQuery['scope'])->toContain('w_organization_social')
        ->and($orgQuery['scope'])->not->toContain('urn:li:organization:authorize');
});

it('asks for nothing the app cannot hold', function () {
    $recorder = new GuzzleRecorder();

    // A Community Management API app holds no OpenID Connect, and asking
    // for what it does not hold refuses the whole authorization request.
    $withoutSignIn = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(signInScope: false), $recorder->client());
    parse_str((string) parse_url($withoutSignIn->authorizationUrl('s'), PHP_URL_QUERY), $query);

    expect($query['scope'])->not->toContain('openid')
        ->and($query['scope'])->not->toContain('profile')
        ->and($query['scope'])->not->toContain('email')
        ->and($query['scope'])->toContain('w_member_social');
});

it('names the member by the access-control list when the token has no openid', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(), $recorder->client([
        [
            'body' => '{"elements":[{"role":"ADMINISTRATOR","organization":"urn:li:organization:2414183","roleAssignee":"urn:li:person:abCdEf","state":"APPROVED"}],"paging":{"count":10,"start":0,"links":[]}}',
        ],
    ]));

    // No `openid` in the token: the member is named by the list, which says
    // who the role was given to.
    $accounts = $provider->resolveAccounts(new OAuthTokenSet(
        accessToken: 'at',
        scopes: ['r_organization_admin'],
    ));

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->externalId)->toBe('urn:li:person:abCdEf')
        ->and($accounts[0]->kind->value)->toBe('member')
        ->and($accounts[1]->externalId)->toBe('urn:li:organization:2414183');

    // The OpenID Connect endpoint is not called: the token cannot read it.
    $urls = array_map(
        static fn(array $entry): string => (string) $entry['request']->getUri(),
        $recorder->requests,
    );

    expect($urls)->not->toContain('https://api.linkedin.com/v2/userinfo');
});

it('names no member when the access-control list names an organisation', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(), $recorder->client([
        [
            'body' => '{"elements":[{"role":"ADMINISTRATOR","organization":"urn:li:organization:2414183","roleAssignee":"urn:li:organization:99","state":"APPROVED"}],"paging":{"count":10,"start":0,"links":[]}}',
        ],
    ]));

    // A role can be given to an organisation. That urn names no member, so
    // the connection says so instead of storing an id that names nobody.
    expect(fn() => $provider->resolveAccounts(new OAuthTokenSet(
        accessToken: 'at',
        scopes: ['r_organization_admin'],
    )))->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'names no member');
});

it('names the member that follows an organisation in the access-control list', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(), $recorder->client([
        [
            'body' => '{"elements":[{"role":"ADMINISTRATOR","organization":"urn:li:organization:2414183","roleAssignee":"urn:li:organization:99","state":"APPROVED"},{"role":"ADMINISTRATOR","organization":"urn:li:organization:2414183","roleAssignee":"urn:li:person:abCdEf","state":"APPROVED"}],"paging":{"count":10,"start":0,"links":[]}}',
        ],
        [
            'body' => '{"localizedName":"Fabeat GmbH"}',
        ],
    ]));

    $accounts = $provider->resolveAccounts(new OAuthTokenSet(
        accessToken: 'at',
        scopes: ['r_organization_admin'],
    ));

    expect($accounts[0]->externalId)->toBe('urn:li:person:abCdEf')
        ->and($accounts[0]->kind->value)->toBe('member');
});

it('exchanges the code for tokens, without a pkce verifier', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(), $recorder->client([['body' => '{"access_token":"at","expires_in":5184000,"refresh_token":"rt","scope":"openid,profile,w_member_social"}']]));

    $tokens = $provider->exchangeCode('the-code');

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
        ->and($fields['client_id'])->toBe('client-id')
        ->and($fields['client_secret'])->toBe('client-secret')
        ->and($fields['redirect_uri'])->toBe(SocialFixtures::OAUTH_CALLBACK);

    // The verifier is the one parameter that makes LinkedIn answer the
    // exchange with "invalid_client — Client authentication failed".
    expect($fields)->not->toHaveKey('code_verifier');
});

it('refreshes without a pkce verifier, which a refresh is not', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(), $recorder->client([['body' => '{"access_token":"at2","expires_in":5184000,"refresh_token":"rt2"}']]));

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
    $provider = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(), $recorder->client());

    expect(fn() => $provider->refresh(new OAuthTokenSet(accessToken: 'at')))
        ->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'no refresh token');
});

it('resolves the member account and its reactions-only note', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(), $recorder->client([['body' => '{"sub":"abc123","name":"Fabeat Person","email":"fabeat@example.test"}']]));

    $accounts = $provider->resolveAccounts(new OAuthTokenSet(accessToken: 'at', scopes: ['openid']));

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->externalId)->toBe('urn:li:person:abc123')
        ->and($accounts[0]->kind->value)->toBe('member')
        ->and($accounts[0]->displayName)->toBe('Fabeat Person');

    $request = $recorder->requests[0]['request'];
    expect((string) $request->getUri())->toBe('https://api.linkedin.com/v2/userinfo')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer at');
});
