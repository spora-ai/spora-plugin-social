<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Service\SocialAppUrlProbe;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Plugins\Social\Service\SocialMetricsService;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Tests\Support\FakeProviderResolver;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A metrics service that records the requests it makes.
 *
 * @param list<array{method: string, url: string, options: array<string, mixed>}> $seen
 */
function recordingMetricsService(array &$seen): SocialMetricsService
{
    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse('{}');
    });

    return new SocialMetricsService(
        new Spora\Plugins\Social\Publish\PlatformHttpClient($http),
        metricsConnectionService(),
        apiVersions(),
    );
}

/**
 * The account service the metrics service reads its tokens through.
 */
function metricsConnectionService(): SocialConnectionService
{
    return new SocialConnectionService(
        new SocialSettings(socialConfig()),
        new FakeProviderResolver([]),
        new Spora\Plugins\Social\OAuth\SocialStateCodec(securityManager()),
        securityManager(),
        new SocialAppUrlProbe(new MockHttpClient([
            new MockResponse('', ['http_code' => 200]),
        ])),
    );
}

/**
 * A LinkedIn account whose token carries the given scopes.
 *
 * @param list<string> $scopes
 */
function linkedInAccountWithScopes(int $principalId, string $kind, array $scopes): SocialAccount
{
    $connection = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'linkedin-token',
        'scopes'       => implode(' ', $scopes),
        'expires_at'   => new DateTimeImmutable('+50 days'),
    ]);

    return createSocialAccount($principalId, 'linkedin', $kind, ['connection' => $connection]);
}

/**
 * A published target on the given account.
 */
function publishedTargetOn(SocialAccount $account, string $externalId): SocialPostTarget
{
    $post = new SocialPost();
    $post->principal_id = (int) $account->principal_id;
    $post->body = 'We shipped…';
    $post->status = SocialPost::STATUS_PUBLISHED;
    $post->save();

    $target = new SocialPostTarget();
    $target->post_id = $post->id;
    $target->account_id = $account->id;
    $target->platform = 'linkedin';
    $target->status = SocialPostTarget::STATUS_PUBLISHED;
    $target->external_id = $externalId;
    $target->published_at = Carbon::now()->subHour();
    $target->save();

    return $target;
}

/**
 * A published target with a reading of the given counters.
 *
 * @param array<string, int|null> $counters
 */
function readingFor(int $principalId, string $platform, array $counters, string $kind = 'organization'): void
{
    $target = publishedTarget($principalId, $platform, $kind, 'urn:li:share:' . $platform);

    $row = new Spora\Plugins\Social\Model\SocialPostMetric();
    $row->post_id = $target->post_id;
    $row->target_id = $target->id;
    $row->metric_date = gmdate('Y-m-d');
    $row->measured_at = Carbon::now();

    foreach ($counters as $counter => $value) {
        $row->{$counter} = $value;
    }

    $row->save();
}

it('leaves the reach of a linkedin member post empty and says why', function () {
    $service = metricsService([
        ['body' => '{"likesSummary":{"totalLikes":12},"commentsSummary":{"totalFirstLevelComments":3}}'],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = publishedTarget($principalId, 'linkedin', 'member', 'urn:li:share:9');

    $row = $service->collect($target->fresh() ?? $target);

    expect($row)->not->toBeNull()
        ->and($row?->reach)->toBeNull()
        ->and($row?->impressions)->toBeNull()
        ->and($row?->likes)->toBe(12)
        ->and($row?->comments)->toBe(3);
});

it('counts an organisation post\'s reach in unique impressions, not impressions', function () {
    $service = metricsService([
        // The social actions answer empty: "A share without any social
        // action activity returns an empty JSON response." The reactions
        // then come from the share statistics.
        ['body' => '{}'],
        ['body' => '{"elements":[{"totalShareStatistics":{"uniqueImpressionsCount":9327,"impressionCount":14490816,"likeCount":52,"commentCount":70,"shareCount":0,"clickCount":109276}}]}'],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = publishedTarget($principalId, 'linkedin', 'organization', 'urn:li:share:9');

    $row = $service->collect($target->fresh() ?? $target);

    // The share statistics name both counts: "uniqueImpressionsCount —
    // Number of unique impressions" and "impressionCount — Number of
    // impressions". Reach is the unique one, so it counts people the way
    // Facebook's `post_impressions_unique` and Instagram's `reach` do.
    //
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/share-statistics
    expect($row?->reach)->toBe(9327)
        ->and($row?->impressions)->toBe(14490816)
        ->and($row?->likes)->toBe(52)
        ->and($row?->comments)->toBe(70)
        ->and($row?->clicks)->toBe(109276);
});

it('reads the up-to-date reactions of a linkedin organisation post', function () {
    $service = metricsService([
        ['body' => '{"likesSummary":{"totalLikes":12},"commentsSummary":{"totalFirstLevelComments":3}}'],
        ['body' => '{"elements":[{"totalShareStatistics":{"uniqueImpressionsCount":9327,"impressionCount":14490816,"likeCount":20,"commentCount":4,"shareCount":3,"clickCount":18}}]}'],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = publishedTarget($principalId, 'linkedin', 'organization', 'urn:li:share:9');

    $row = $service->collect($target->fresh() ?? $target);

    // "The socialActions endpoint provides up-to-date counts of likes and
    // comments for a share that matches the LinkedIn feed" — so the social
    // actions win over the share statistics' lifetime counts.
    //
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/share-statistics
    expect($row?->likes)->toBe(12)
        ->and($row?->comments)->toBe(3)
        ->and($row?->reach)->toBe(9327)
        ->and($row?->impressions)->toBe(14490816)
        ->and($row?->clicks)->toBe(18);
});

it('reads an organisation post\'s reactions from the social actions endpoint', function () {
    $seen = [];

    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse('{}');
    });

    $service = new SocialMetricsService(
        new Spora\Plugins\Social\Publish\PlatformHttpClient($http),
        metricsConnectionService(),
        apiVersions(),
    );

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = publishedTarget($principalId, 'linkedin', 'organization', 'urn:li:share:9');

    $service->collect($target->fresh() ?? $target);

    // The social actions of a share, and the share statistics beside it.
    //
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
    expect($seen)->toHaveCount(2)
        ->and($seen[0]['method'])->toBe('GET')
        ->and($seen[0]['url'])->toBe('https://api.linkedin.com/rest/socialActions/urn%3Ali%3Ashare%3A9')
        ->and($seen[1]['url'])->toStartWith('https://api.linkedin.com/rest/organizationalEntityShareStatistics');

    $headers = [];
    foreach ($seen[0]['options']['headers'] ?? [] as $header) {
        [$name, $value] = explode(': ', (string) $header, 2);
        $headers[strtolower($name)] = $value;
    }

    expect($headers['linkedin-version'] ?? null)->toBe('202610')
        ->and($headers['x-restli-protocol-version'] ?? null)->toBe('2.0.0');
});

it('skips the social actions read the token is not allowed to make', function () {
    $seen = [];

    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse(
            '{"elements":[{"totalShareStatistics":{"uniqueImpressionsCount":9327,"impressionCount":14490816,'
            . '"likeCount":52,"commentCount":70,"shareCount":3,"clickCount":109276}}]}',
        );
    });

    $service = new SocialMetricsService(
        new Spora\Plugins\Social\Publish\PlatformHttpClient($http),
        metricsConnectionService(),
        apiVersions(),
    );

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    // The token carries the scopes it was granted, and the social actions
    // of an organisation's shares are not among them.
    $account = linkedInAccountWithScopes($principalId, 'organization', [
        'openid',
        'profile',
        'email',
        'w_member_social',
        'rw_organization_admin',
    ]);

    $target = publishedTargetOn($account, 'urn:li:share:9');

    $row = $service->collect($target->fresh() ?? $target);

    // A request that would always be refused is not worth making every
    // day: only the share statistics are read.
    expect($seen)->toHaveCount(1)
        ->and($seen[0]['url'])->toStartWith('https://api.linkedin.com/rest/organizationalEntityShareStatistics');

    // And the reactions are the share statistics' counts, exactly as they
    // were before the social-actions read existed.
    expect($row?->likes)->toBe(52)
        ->and($row?->comments)->toBe(70)
        ->and($row?->reach)->toBe(9327)
        ->and($row?->impressions)->toBe(14490816);
});

it('skips the social actions read a member token is not allowed to make', function () {
    $seen = [];

    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse('{}');
    });

    $service = new SocialMetricsService(
        new Spora\Plugins\Social\Publish\PlatformHttpClient($http),
        metricsConnectionService(),
        apiVersions(),
    );

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = linkedInAccountWithScopes($principalId, 'member', [
        'openid',
        'profile',
        'email',
        'w_member_social',
    ]);

    $target = publishedTargetOn($account, 'urn:li:share:9');

    $row = $service->collect($target->fresh() ?? $target);

    // The member path has no other read to fall back on, so the counters
    // stay empty — the same as a refused read.
    expect($seen)->toHaveCount(0)
        ->and($row?->likes)->toBeNull()
        ->and($row?->comments)->toBeNull();
});

it('skips the share statistics read the token is not allowed to make', function () {
    $seen = [];

    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse('{}');
    });

    $service = new SocialMetricsService(
        new Spora\Plugins\Social\Publish\PlatformHttpClient($http),
        metricsConnectionService(),
        apiVersions(),
    );

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    // The token carries neither read permission, so neither read can
    // succeed.
    $account = linkedInAccountWithScopes($principalId, 'organization', [
        'openid',
        'profile',
        'email',
        'w_member_social',
    ]);

    $target = publishedTargetOn($account, 'urn:li:share:9');

    $row = $service->collect($target->fresh() ?? $target);

    // Neither read is made: both would always be refused.
    expect($seen)->toHaveCount(0)
        ->and($row?->reach)->toBeNull()
        ->and($row?->impressions)->toBeNull()
        ->and($row?->likes)->toBeNull()
        ->and($row?->comments)->toBeNull()
        ->and($row?->shares)->toBeNull()
        ->and($row?->clicks)->toBeNull();
});

it('attempts the reads a token with no recorded scopes cannot be judged for', function () {
    $seen = [];

    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        // A read the token is not allowed to make is refused.
        return new MockResponse('{"error":{"message":"ACCESS_DENIED","status":403}}', ['http_code' => 403]);
    });

    $service = new SocialMetricsService(
        new Spora\Plugins\Social\Publish\PlatformHttpClient($http),
        metricsConnectionService(),
        apiVersions(),
    );

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = publishedTarget($principalId, 'linkedin', 'organization', 'urn:li:share:9');

    $row = $service->collect($target->fresh() ?? $target);

    // The token's scopes were never recorded, so the plugin cannot judge
    // the reads and makes them: a refused read is the platform's answer.
    // The counters stay empty either way, and a zero would read as
    // "nobody saw it".
    expect($seen)->toHaveCount(2)
        ->and($row?->reach)->toBeNull()
        ->and($row?->impressions)->toBeNull()
        ->and($row?->likes)->toBeNull()
        ->and($row?->comments)->toBeNull()
        ->and($row?->shares)->toBeNull()
        ->and($row?->clicks)->toBeNull();
});

it('reads the impressions of an instagram post', function () {
    $service = metricsService([
        ['body' => '{"data":[{"name":"impressions","values":[{"value":900}]},{"name":"reach","values":[{"value":500}]},{"name":"likes","values":[{"value":30}]}]}'],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = publishedTarget($principalId, 'instagram', 'business', '17900000000000002');

    $row = $service->collect($target->fresh() ?? $target);

    expect($row?->impressions)->toBe(900)
        ->and($row?->reach)->toBe(500)
        ->and($row?->likes)->toBe(30);
});

it('reads the facebook insights with the page token, not the user token', function () {
    $seen = [];

    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse('{"data":[]}');
    });

    $service = new SocialMetricsService(
        new Spora\Plugins\Social\Publish\PlatformHttpClient($http),
        metricsConnectionService(),
        apiVersions(),
    );

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    // One login, one token: the Page and the Instagram account share it.
    $connection = createSocialConnection($principalId, 'meta', [
        'access_token' => 'the-suat',
        'expires_at'   => new DateTimeImmutable('+50 days'),
    ]);

    $account = createSocialAccount($principalId, 'facebook', 'page', ['connection' => $connection]);

    $post = new SocialPost();
    $post->principal_id = $principalId;
    $post->body = 'We shipped…';
    $post->status = SocialPost::STATUS_PUBLISHED;
    $post->save();

    $target = new SocialPostTarget();
    $target->post_id = $post->id;
    $target->account_id = $account->id;
    $target->platform = 'facebook';
    $target->status = SocialPostTarget::STATUS_PUBLISHED;
    $target->external_id = '111_222';
    $target->published_at = Carbon::now()->subHour();
    $target->save();

    $service->collect($target->fresh() ?? $target);

    // The connection's token is the one on the call: the system-user token
    // covers every asset the client shared.
    //
    // @see https://developers.facebook.com/documentation/pages-api/platforminsights/page
    expect($seen)->not->toBeEmpty();

    foreach ($seen as $call) {
        expect($call['url'])->toContain('access_token=the-suat');
    }
});

it('keeps the networks apart and labels the combined figure as a rough one', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    readingFor($principalId, 'linkedin', ['reach' => 100, 'impressions' => 200, 'likes' => 5]);
    readingFor($principalId, 'instagram', ['reach' => 50, 'impressions' => 60, 'likes' => 7]);

    $summary = metricsService([])->summary($principalId);

    expect($summary['totals']['reach'])->toBe(150)
        ->and($summary['totals']['impressions'])->toBe(260)
        ->and($summary['totals']['likes'])->toBe(12);

    $byPlatform = [];
    foreach ($summary['by_network'] as $network) {
        $byPlatform[$network['platform']] = $network;
    }

    expect($byPlatform['linkedin']['reach'])->toBe(100)
        ->and($byPlatform['instagram']['reach'])->toBe(50);
});

it('carries the linkedin caveat in the notes when a member post is among them', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    readingFor($principalId, 'linkedin', ['reach' => null, 'impressions' => null, 'likes' => 5], 'member');

    $summary = metricsService([])->summary($principalId);

    expect(implode(' ', $summary['notes']))
        ->toContain('LinkedIn member posts report reactions only');
});

it('leaves the reach of a network that reported none empty and keeps a reported zero', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    readingFor($principalId, 'linkedin', ['reach' => 100]);
    readingFor($principalId, 'facebook', ['reach' => 0]);

    $summary = metricsService([])->summary($principalId);

    $byPlatform = [];
    foreach ($summary['by_network'] as $network) {
        $byPlatform[$network['platform']] = $network;
    }

    // A network that reported nothing is not a network that reported zero.
    expect($byPlatform['facebook']['reach'])->toBe(0)
        ->and($byPlatform['linkedin']['reach'])->toBe(100);
});

it('leaves the total reach empty when no network reported one', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    readingFor($principalId, 'linkedin', ['reach' => null, 'impressions' => null]);
    readingFor($principalId, 'facebook', ['reach' => null, 'impressions' => null]);

    $summary = metricsService([])->summary($principalId);

    expect($summary['totals']['reach'])->toBeNull();
});

it('leaves the platform out of a day it reported no reach for', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = publishedTarget($principalId, 'linkedin', 'organization', 'urn:li:share:9');

    $row = new Spora\Plugins\Social\Model\SocialPostMetric();
    $row->post_id = $target->post_id;
    $row->target_id = $target->id;
    $row->metric_date = gmdate('Y-m-d', strtotime('-10 days'));
    $row->measured_at = Carbon::now();
    $row->reach = 100;
    $row->save();

    $summary = metricsService([])->summary($principalId, 1);

    expect($summary['by_network'])->toBe([]);
});
