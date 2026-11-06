<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Aggregator\AggregatorMetricsReader;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostMetric;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Publish\PlatformHttpClient;
use Spora\Plugins\Social\Service\SocialMetricsService;
use Spora\Plugins\Social\Tests\Support\ZernioRecorder;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The metrics service, with the aggregator's reader against a mocked
 * Zernio.
 */
function aggregatorMetricsService(ZernioRecorder $recorder): SocialMetricsService
{
    $aggregators = aggregatorService($recorder->client());

    return new SocialMetricsService(
        new PlatformHttpClient(new MockHttpClient([])),
        connectionService(),
        apiVersions(),
        new AggregatorMetricsReader(zernioClient($recorder->client()), $aggregators),
    );
}

/**
 * A published target on an aggregator's post.
 *
 * The target's id is the aggregator's post id — that is what the
 * analytics lookup needs.
 */
function zernioTarget(int $principalId, string $network = 'bluesky', string $externalId = 'id_001'): SocialPostTarget
{
    $account = zernioAccount($principalId, $network, 'id_002');

    $post = new SocialPost();
    $post->principal_id = $principalId;
    $post->body = 'We shipped…';
    $post->status = SocialPost::STATUS_PUBLISHED;
    $post->save();

    $target = new SocialPostTarget();
    $target->post_id = $post->id;
    $target->account_id = $account->id;
    $target->platform = $network;
    $target->via = 'zernio';
    $target->status = SocialPostTarget::STATUS_PUBLISHED;
    $target->external_id = $externalId;
    $target->published_at = Carbon::now()->subHour();
    $target->save();

    return $target;
}

it('lands the numbers of a zernio target in the metrics table', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('analytics')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = zernioTarget($principalId);

    $row = aggregatorMetricsService($recorder)->collect($target);

    expect($row)->not->toBeNull()
        ->and($row?->impressions)->toBe(15420)
        ->and($row?->reach)->toBe(12350)
        ->and($row?->likes)->toBe(342)
        ->and($row?->comments)->toBe(28)
        ->and($row?->shares)->toBe(45)
        ->and($row?->clicks)->toBe(189);

    // The lookup is the aggregator's post id.
    expect($recorder->url())->toBe('https://zernio.com/api/v1/analytics?postId=id_001');

    expect(SocialPostMetric::query()->where('target_id', $target->id)->count())->toBe(1);
});

it('leaves the numbers empty when the plan carries no analytics, and says why', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('analytics-addon-required'), 'status' => 402],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = zernioTarget($principalId);

    $row = aggregatorMetricsService($recorder)->collect($target);

    // The reading is not an error: the target has no numbers, and the
    // reason is on it.
    expect($row)->not->toBeNull()
        ->and($row?->impressions)->toBeNull()
        ->and($row?->reach)->toBeNull()
        ->and($row?->platform_metrics['note'] ?? null)->toContain('add-on');
});

it('reports the aggregate with a note saying what it covers', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('analytics-aggregate')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = zernioTarget($principalId);

    $service = aggregatorMetricsService($recorder);

    $row = $service->collect($target);

    expect($row?->impressions)->toBe(15420);

    // The note says the numbers cover every network in that post — a
    // number without its scope reads as a number about this account.
    $analytics = $service->postAnalytics((int) $target->post_id, $principalId);

    expect($analytics['targets'][0]['report_note'] ?? null)->toContain('every network');
});

it('skips the reading while the aggregator is still syncing', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('analytics-syncing'), 'status' => 202],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = zernioTarget($principalId);

    $row = aggregatorMetricsService($recorder)->collect($target);

    // Nothing is written down, and the daily job picks it up tomorrow.
    expect($row)->toBeNull()
        ->and(SocialPostMetric::query()->where('target_id', $target->id)->count())->toBe(0);
});

it('reads the numbers of a network the plugin does not reach natively', function () {
    // The reader is channel-aware now: the aggregator's route is not the
    // plugin's own, and it has its own reader.
    expect(Spora\Plugins\Social\Service\PlatformMetricsReader::reads(
        Spora\Plugins\Social\Model\SocialChannel::tryFrom('zernio', 'bluesky'),
    ))->toBeTrue();
});
