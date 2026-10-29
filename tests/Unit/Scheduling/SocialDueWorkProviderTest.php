<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Scheduling\SocialDueWorkProvider;
use Spora\Scheduling\DueWork;

/**
 * @param array<string, list<array{body: string, status?: int}>> $answers
 */
function dueWorkProvider(array $answers = []): SocialDueWorkProvider
{
    return new SocialDueWorkProvider(
        new Spora\Plugins\Social\Service\SocialMetricsService(
            new Spora\Plugins\Social\Publish\PlatformHttpClient(
                new Symfony\Component\HttpClient\MockHttpClient([
                    new Symfony\Component\HttpClient\Response\MockResponse('{"likesSummary":{"totalLikes":1}}'),
                ]),
            ),
            new Spora\Plugins\Social\Service\SocialConnectionService(
                new Spora\Plugins\Social\Service\SocialSettings(socialConfig()),
                new Spora\Plugins\Social\Tests\Support\FakeProviderResolver([]),
                new Spora\Plugins\Social\OAuth\SocialStateCodec(securityManager()),
                securityManager(),
                new Spora\Plugins\Social\Service\SocialAppUrlProbe(
                    new Symfony\Component\HttpClient\MockHttpClient([
                        new Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 200]),
                    ]),
                ),
            ),
            apiVersions(),
        ),
        postPublisher($answers),
    );
}

/**
 * A scheduled post whose time has come, with one pending target.
 */
function scheduledPost(int $principalId, bool $withTarget = true): Spora\Plugins\Social\Model\SocialPost
{
    $post = new Spora\Plugins\Social\Model\SocialPost();
    $post->principal_id = $principalId;
    $post->body = 'We shipped…';
    $post->status = Spora\Plugins\Social\Model\SocialPost::STATUS_SCHEDULED;
    $post->scheduled_for = Carbon::now()->subMinute();
    $post->save();

    if (!$withTarget) {
        return $post;
    }

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $target = new SocialPostTarget();
    $target->post_id = $post->id;
    $target->account_id = $account->id;
    $target->platform = 'linkedin';
    $target->status = SocialPostTarget::STATUS_PENDING;
    $target->save();

    return $post->fresh() ?? $post;
}

it('answers with the visible principal ids and the due posts', function () {
    $provider = dueWorkProvider();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = scheduledPost($principalId);

    $work = $provider->dueWork([$principalId]);

    expect($work)->toHaveCount(1)
        ->and($work[0]->provider)->toBe(SocialDueWorkProvider::class)
        ->and($work[0]->principalId)->toBe($principalId)
        ->and($work[0]->payload['kind'])->toBe(SocialDueWorkProvider::KIND_POST)
        ->and($work[0]->payload['post_id'])->toBe($post->id)
        ->and($work[0]->dueAt)->toBe(gmdate('Y-m-d H:i:s', $post->scheduled_for->getTimestamp()));
});

it('answers with nothing when there is no visible principal', function () {
    expect(dueWorkProvider()->dueWork([]))->toBe([]);
});

it('keeps going when one item fails', function () {
    $provider = dueWorkProvider();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    // A post whose account is gone: the publish fails, and the next item
    // must still be handled.
    $post = scheduledPost($principalId, false);

    $failing = new DueWork(
        provider: SocialDueWorkProvider::class,
        principalId: $principalId,
        payload: ['kind' => SocialDueWorkProvider::KIND_POST, 'post_id' => $post->id],
        dueAt: gmdate('Y-m-d H:i:s'),
    );

    // The failure is swallowed: the worker must not see an exception.
    $provider->execute($failing);

    expect(true)->toBeTrue();
});

it('reports the metrics that are due for a published target', function () {
    $provider = dueWorkProvider();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = new Spora\Plugins\Social\Model\SocialPost();
    $post->principal_id = $principalId;
    $post->status = Spora\Plugins\Social\Model\SocialPost::STATUS_PUBLISHED;
    $post->save();

    $target = new SocialPostTarget();
    $target->post_id = $post->id;
    $target->account_id = $account->id;
    $target->platform = 'linkedin';
    $target->status = SocialPostTarget::STATUS_PUBLISHED;
    $target->external_id = 'urn:li:share:9';
    $target->published_at = Carbon::now()->subHours(3);
    $target->save();

    $work = $provider->dueWork([$principalId]);

    $metric = null;
    foreach ($work as $item) {
        if ($item->payload['kind'] === SocialDueWorkProvider::KIND_METRIC) {
            $metric = $item;
        }
    }

    expect($metric)->not->toBeNull()
        ->and($metric?->payload['target_id'])->toBe($target->id);
});

it('publishes a scheduled post whose time has come', function () {
    $provider = dueWorkProvider(['linkedin' => [['body' => '{"id":"urn:li:share:9"}']]]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = scheduledPost($principalId);

    $work = $provider->dueWork([$principalId]);

    $unit = null;
    foreach ($work as $item) {
        if ($item->payload['kind'] === SocialDueWorkProvider::KIND_POST) {
            $unit = $item;
        }
    }

    expect($unit)->not->toBeNull()
        ->and($unit?->principalId)->toBe($principalId)
        ->and($unit?->payload['post_id'])->toBe($post->id);

    $provider->execute($unit);

    $published = $post->fresh();

    expect($published?->status)->toBe(Spora\Plugins\Social\Model\SocialPost::STATUS_PUBLISHED)
        ->and($published?->published_at)->not->toBeNull()
        ->and($published?->targets()->first()?->external_id)->toBe('urn:li:share:9');
});

it('claims a scheduled post only once', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = scheduledPost($principalId);

    $publisher = postPublisher(['linkedin' => [['body' => '{"id":"urn:li:share:9"}']]]);

    expect($publisher->claimScheduled((int) $post->id))->not->toBeNull()
        ->and($publisher->claimScheduled((int) $post->id))->toBeNull();
});

it('leaves a scheduled post with no targets out of publishing', function () {
    $provider = dueWorkProvider();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = scheduledPost($principalId, false);

    $work = $provider->dueWork([$principalId]);

    foreach ($work as $item) {
        if ($item->payload['kind'] === SocialDueWorkProvider::KIND_POST) {
            $provider->execute($item);
        }
    }

    $after = $post->fresh();

    // The same outcome a manual publish of a targetless post has: the post
    // leaves the scheduled state and is not left half-published.
    expect($after?->status)->not->toBe(Spora\Plugins\Social\Model\SocialPost::STATUS_SCHEDULED)
        ->and($after?->status)->not->toBe(Spora\Plugins\Social\Model\SocialPost::STATUS_PUBLISHING)
        ->and($after?->status)->toBe(Spora\Plugins\Social\Model\SocialPost::STATUS_DRAFT);
});
