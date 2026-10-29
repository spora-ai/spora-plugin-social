<?php

declare(strict_types=1);

use Spora\Plugins\Social\OAuth\LinkedInPermissions;
use Spora\Plugins\Social\OAuth\LinkedInProvider;
use Spora\Plugins\Social\OAuth\MetaProvider;
use Spora\Plugins\Social\OAuth\OAuthTokenSet;
use Spora\Plugins\Social\Publish\InstagramAdapter;
use Spora\Plugins\Social\Publish\PublishMedia;
use Spora\Plugins\Social\Publish\PublishRequest;
use Spora\Plugins\Social\Tests\Support\GuzzleRecorder;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The wire contract, checked against the documentation.
 *
 * Every expectation here is transcribed from the official docs, with the
 * URL it came from. A contract test somebody can re-check when the API
 * moves is worth more than a fixture that pins the code's own idea of it.
 */
it('keeps the graph version in one place, so no endpoint carries its own', function () {
    $root = dirname(__DIR__, 2) . '/src';

    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // A version pinned into an endpoint, e.g. graph.facebook.com/v21.0.
        if (preg_match_all('#(graph\.facebook\.com|graph\.instagram\.com|www\.facebook\.com)/v\d+\.\d+#', $contents, $matches) > 0) {
            $offenders[] = $file->getPathname() . ': ' . implode(', ', $matches[0]);
        }
    }

    expect($offenders)->toBe([]);
});

it('cannot be built without the versions, so a caller cannot forget them', function () {
    $constructor = (new ReflectionClass(Spora\Plugins\Social\Service\ApiVersions::class))->getConstructor();

    expect($constructor)->not->toBeNull();

    // A default here would let a caller that forgot the versions get the
    // plugin's defaults and talk to the platform as the wrong version
    // without knowing it.
    foreach ($constructor->getParameters() as $parameter) {
        expect($parameter->isDefaultValueAvailable())->toBeFalse();
    }
});

it('declares each read permission as its own setting, off by default', function () {
    $definitions = [];

    $provider = new Spora\Plugins\Social\Settings\SocialSettingsProvider(
        new Spora\Plugins\Social\Service\SocialSettings(new Spora\Core\Config([
            'SPORA_APP_URL' => 'https://spora.test',
        ])),
    );

    foreach ($provider->settings() as $definition) {
        $definitions[$definition->key] = $definition;
    }

    $expected = [
        'LINKEDIN_READ_ORG_ACLS'      => 'r_organization_admin',
        'LINKEDIN_READ_ORG_STATS'     => 'rw_organization_admin',
        'LINKEDIN_READ_MEMBER_FEED'   => 'r_member_social_feed',
        'LINKEDIN_READ_ORG_FEED'      => 'r_organization_social_feed',
    ];

    foreach ($expected as $key => $permission) {
        expect($definitions)->toHaveKey($key);

        $definition = $definitions[$key];

        // Off by default: an app that asks for a permission it does not
        // hold is refused the whole authorization request.
        expect($definition->default)->toBeFalse()
            ->and($definition->description)->toContain($permission);
    }
});

it('carries the plugin rules the frontend used to keep a copy of', function () {
    $rules = Spora\Plugins\Social\Service\PlatformFit::apiRules();

    foreach ($rules as $rule) {
        // One attachment per post: the publish sends one file, so a second
        // is a file the operator believes is going out and is not.
        expect($rule['media_count'])->toBe(1);
    }

    // Instagram fetches the media from the internet; the other two take
    // the bytes.
    expect($rules['instagram']['fetches_media'])->toBeTrue()
        ->and($rules['linkedin']['fetches_media'])->toBeFalse()
        ->and($rules['facebook']['fetches_media'])->toBeFalse();
});

it('sends the linkedin version header on every rest request', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(readOrganizationAcls: true), $recorder->client([
        ['body' => SocialFixtures::META_SUBJECT],
        [
            'body' => '{"elements":[{"role":"ADMINISTRATOR","organization":"urn:li:organization:2414183","roleAssignee":"urn:li:person:abCdEf","state":"APPROVED"}]}',
        ],
        ['body' => '{"localizedName":"Fabeat GmbH","id":2414183}'],
    ]), apiVersions());

    // "All APIs require the request header Linkedin-Version: {Version in
    // YYYYMM format}. All APIs require the request header
    // `X-Restli-Protocol-Version: 2.0.0`." — every `/rest/*` call the
    // provider makes carries both.
    //
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-access-control-by-role
    $accounts = $provider->resolveAccounts(new OAuthTokenSet(
        accessToken: 'INSERT_TOKEN',
        scopes: ['r_organization_admin'],
    ));

    expect($accounts)->toHaveCount(2);

    $restRequests = array_values(array_filter(
        $recorder->requests,
        static fn(array $entry): bool => str_starts_with($entry['request']->getUri()->getPath(), '/rest/'),
    ));

    expect($restRequests)->toHaveCount(2);

    foreach ($restRequests as $entry) {
        $headers = recordedHeaders($entry['request']);

        expect($headers['linkedin-version'] ?? null)->toBe('202610')
            ->and($headers['x-restli-protocol-version'] ?? null)->toBe('2.0.0')
            ->and($headers['authorization'] ?? null)->toBe('Bearer INSERT_TOKEN');
    }

    $urls = array_map(
        static fn(array $entry): string => (string) $entry['request']->getUri(),
        $restRequests,
    );

    expect($urls[0])->toBe('https://api.linkedin.com/rest/organizationAcls?q=roleAssignee')
        ->and($urls[1])->toBe('https://api.linkedin.com/rest/organizations/2414183');
});

it('does not send the linkedin version header to the oidc userinfo endpoint', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(), $recorder->client([['body' => SocialFixtures::META_SUBJECT]]), apiVersions());

    $provider->resolveAccounts(new OAuthTokenSet(accessToken: 'INSERT_TOKEN'));

    $request = $recorder->requests[0]['request'];

    // The userinfo endpoint is OpenID Connect, not a `/rest/*` API, and
    // refuses a request that carries the header.
    expect((string) $request->getUri())->toBe('https://api.linkedin.com/v2/userinfo')
        ->and($request->getHeaderLine('Linkedin-Version'))->toBe('');
});

it('reads the organisations from the documented acl response', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(readOrganizationAcls: true), $recorder->client([
        [
            'body' => SocialFixtures::META_SUBJECT,
        ],
        [
            'body' => '{"elements":[{"role":"ADMINISTRATOR","organization":"urn:li:organization:2414183","roleAssignee":"urn:li:person:abCdEf","state":"APPROVED"}],"paging":{"count":10,"start":0,"links":[]}}',
        ],
        [
            'body' => '{"localizedName":"Fabeat GmbH","id":2414183}',
        ],
    ]), apiVersions());

    $accounts = $provider->resolveAccounts(new OAuthTokenSet(
        accessToken: 'INSERT_TOKEN',
        scopes: ['r_organization_admin'],
    ));

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->externalId)->toBe('urn:li:person:abc123')
        ->and($accounts[1]->externalId)->toBe('urn:li:organization:2414183')
        ->and($accounts[1]->kind->value)->toBe('organization')
        ->and($accounts[1]->displayName)->toBe('Fabeat GmbH');

    // "Find a Member's Organization Access Control Information":
    //   GET https://api.linkedin.com/rest/organizationAcls?q=roleAssignee
    //   → { "elements": [ { "organization": "urn:li:organization:2414183", ... } ] }
    //
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-access-control-by-role
    $acl = $recorder->requests[1]['request'];

    expect((string) $acl->getUri())->toBe('https://api.linkedin.com/rest/organizationAcls?q=roleAssignee');
});

it('leaves out an organisation whose role is only requested', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(readOrganizationAcls: true), $recorder->client([
        ['body' => SocialFixtures::META_SUBJECT],
        [
            'body' => '{"elements":[{"role":"ADMINISTRATOR","organization":"urn:li:organization:2414183","roleAssignee":"urn:li:person:abCdEf","state":"REQUESTED"}],"paging":{"count":10,"start":0,"links":[]}}',
        ],
    ]), apiVersions());

    $accounts = $provider->resolveAccounts(new OAuthTokenSet(
        accessToken: 'INSERT_TOKEN',
        scopes: ['r_organization_admin'],
    ));

    // "APPROVED — Role is approved. REQUESTED — Role is requested."
    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->externalId)->toBe('urn:li:person:abc123');
});

it('connects the organisation even when the name lookup is refused', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('client-id', 'client-secret', SocialFixtures::OAUTH_CALLBACK, new LinkedInPermissions(readOrganizationAcls: true), $recorder->client([
        ['body' => SocialFixtures::META_SUBJECT],
        [
            'body' => '{"elements":[{"role":"ADMINISTRATOR","organization":"urn:li:organization:2414183","roleAssignee":"urn:li:person:abCdEf","state":"APPROVED"},{"role":"ADMINISTRATOR","organization":"urn:li:organization:2414184","roleAssignee":"urn:li:person:abCdEf","state":"APPROVED"}]}',
        ],
        [
            'body' => '{"message":"Viewer doesn\'t have permission to the ADMIN_ONLY VisibilityReduction","status":403}',
            'status' => 403,
        ],
        [
            'body' => '{"error":"ADMIN_ONLY","message":"Viewer doesn\'t have permission","status":403}',
            'status' => 403,
        ],
    ]), apiVersions());

    $accounts = $provider->resolveAccounts(new OAuthTokenSet(
        accessToken: 'INSERT_TOKEN',
        scopes: ['r_organization_admin'],
    ));

    // The lookup is admin-only for some organisations. A refusal costs the
    // name, not the connection.
    expect($accounts)->toHaveCount(3)
        ->and($accounts[1]->externalId)->toBe('urn:li:organization:2414183')
        ->and($accounts[1]->displayName)->toBeNull()
        ->and($accounts[2]->externalId)->toBe('urn:li:organization:2414184')
        ->and($accounts[2]->displayName)->toBeNull();
});

it('asks linkedin for exactly the documented permissions', function () {
    $recorder = new GuzzleRecorder();

    // Posts API → "Permissions": w_organization_social, r_organization_social,
    // w_member_social, r_member_social.
    //
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
    $plain = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(), $recorder->client());
    $scopes = scopeListOf($plain->authorizationUrl('state', str_repeat('a', 64)));

    expect($scopes)->toContain('openid')
        ->and($scopes)->toContain('profile')
        ->and($scopes)->toContain('email')
        ->and($scopes)->toContain('w_member_social')
        ->and($scopes)->not->toContain('urn:li:organization:authorize')
        ->and($scopes)->not->toContain('w_organization_social');

    $withOrganization = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(organizationScope: true), $recorder->client());
    $organizationScopes = scopeListOf($withOrganization->authorizationUrl('state', str_repeat('a', 64)));

    expect($organizationScopes)->toContain('w_organization_social');
});

it('asks linkedin for each read permission on its own', function () {
    $recorder = new GuzzleRecorder();

    // Each restricted permission is asked for alone. "An app that asks for
    // a permission it does not hold is refused the whole authorization
    // request", so an app that holds one of the three cannot connect an
    // account if the other two are asked for as well.
    //
    // r_organization_admin — the organisations the member administers.
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-access-control-by-role
    $acls = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(readOrganizationAcls: true), $recorder->client(), apiVersions());
    $aclScopes = scopeListOf($acls->authorizationUrl('state', str_repeat('a', 64)));

    expect($aclScopes)->toContain('r_organization_admin')
        ->and($aclScopes)->not->toContain('rw_organization_admin')
        ->and($aclScopes)->not->toContain('r_member_social_feed');

    // rw_organization_admin — the organisation's share statistics.
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/share-statistics
    $stats = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(readOrganizationStats: true), $recorder->client(), apiVersions());
    $statsScopes = scopeListOf($stats->authorizationUrl('state', str_repeat('a', 64)));

    expect($statsScopes)->toContain('rw_organization_admin')
        ->and($statsScopes)->not->toContain('r_organization_admin')
        ->and($statsScopes)->not->toContain('r_member_social_feed');

    // r_member_social_feed — the reactions on a member post.
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
    $feed = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(readMemberFeed: true), $recorder->client(), apiVersions());
    $feedScopes = scopeListOf($feed->authorizationUrl('state', str_repeat('a', 64)));

    expect($feedScopes)->toContain('r_member_social_feed')
        ->and($feedScopes)->not->toContain('r_organization_admin')
        ->and($feedScopes)->not->toContain('rw_organization_admin');

    // r_organization_social_feed — the reactions on an organisation post.
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
    $organizationFeed = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(readOrganizationFeed: true), $recorder->client(), apiVersions());
    $organizationFeedScopes = scopeListOf($organizationFeed->authorizationUrl('state', str_repeat('a', 64)));

    expect($organizationFeedScopes)->toContain('r_organization_social_feed')
        ->and($organizationFeedScopes)->not->toContain('r_member_social_feed')
        ->and($organizationFeedScopes)->not->toContain('r_organization_admin')
        ->and($organizationFeedScopes)->not->toContain('rw_organization_admin');
});

it('reads the linkedin organization scope from the post api, not the legacy one', function () {
    $recorder = new GuzzleRecorder();
    $provider = new LinkedInProvider('id', 'secret', SocialFixtures::OAUTH_CALLBACK_SHORT, new LinkedInPermissions(organizationScope: true), $recorder->client());

    $url = $provider->authorizationUrl('state', str_repeat('a', 64));

    expect($url)->toContain('w_organization_social')
        ->and($url)->not->toContain('urn%3Ali%3Aorganization%3Aauthorize');
});

it('serves the instagram api from the graph host', function () {
    $seen = [];

    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse('{"data":[{"quota_usage":0,"config":{"quota_total":50}}]}', ['http_code' => 200]);
    });

    $adapter = new InstagramAdapter(platformApi($http));

    $adapter->publish(new PublishRequest(
        externalId: '17841405822304914',
        kind: Spora\Plugins\Social\Model\SocialAccountKind::Business,
        accessToken: 'ACCESS_TOKEN',
        body: 'A caption',
        media: new PublishMedia(
            mimeType: 'image/jpeg',
            publicUrl: 'https://www.example.com/image.jpg',
            filename: 'image.jpg',
        ),
    ));

    // The Instagram API with Unified Login is served from the Graph host,
    // with the Instagram account id in the path.
    foreach ($seen as $request) {
        expect($request['url'])->toStartWith('https://graph.facebook.com/v26.0/');
    }
});

it('exchanges the meta code at the configured graph version', function () {
    $recorder = new GuzzleRecorder();
    $provider = new MetaProvider(
        'client-id',
        'client-secret',
        SocialFixtures::OAUTH_CALLBACK,
        'config-id',
        apiVersions(),
        $recorder->client([
            ['body' => '{"access_token":"the-suat"}'],
            ['body' => '{"client_business_id":"987654321"}'],
        ]),
    );

    $provider->exchangeCode('the-code', str_repeat('v', 64));

    expect((string) $recorder->requests[0]['request']->getUri())
        ->toStartWith('https://graph.facebook.com/v26.0/oauth/access_token?');
});

/**
 * The headers a recorded request carried, lower-cased.
 *
 * @return array<string, string>
 */
function recordedHeaders(Psr\Http\Message\RequestInterface $request): array
{
    $headers = [];

    foreach ($request->getHeaders() as $name => $values) {
        $headers[strtolower($name)] = implode(', ', $values);
    }

    return $headers;
}

/**
 * @return list<string>
 */
function scopeListOf(string $authorizationUrl): array
{
    $query = [];
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    $scope = (string) ($query['scope'] ?? '');

    return array_values(array_filter(array_map('trim', explode(' ', $scope))));
}
