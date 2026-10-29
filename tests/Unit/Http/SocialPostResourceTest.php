<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Http\SocialPostResource;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostMedia;
use Spora\Plugins\Social\Model\SocialPostMetric;
use Spora\Plugins\Social\Model\SocialPostTarget;

/**
 * A post with one published target per entry, each with its own reading.
 *
 * @param list<array{platform: string, counters: array<string, int|null>}> $targets
 */
function postWithReadings(int $principalId, array $targets): SocialPost
{
    $post = new SocialPost();
    $post->principal_id = $principalId;
    $post->body = 'We shipped…';
    $post->status = SocialPost::STATUS_PUBLISHED;
    $post->save();

    foreach ($targets as $entry) {
        $account = publishableAccount($principalId, $entry['platform'], 'organization');

        $target = new SocialPostTarget();
        $target->post_id = $post->id;
        $target->account_id = $account->id;
        $target->platform = $entry['platform'];
        $target->status = SocialPostTarget::STATUS_PUBLISHED;
        $target->external_id = 'urn:li:share:' . $account->id;
        $target->published_at = Carbon::now()->subHour();
        $target->save();

        $row = new SocialPostMetric();
        $row->post_id = $post->id;
        $row->target_id = $target->id;
        $row->metric_date = gmdate('Y-m-d');
        $row->measured_at = Carbon::now();

        foreach ($entry['counters'] as $counter => $value) {
            $row->{$counter} = $value;
        }

        $row->save();
    }

    return $post->fresh() ?? $post;
}

it('gives the media row the asset url the host renders as an image', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, [
        'mime_type' => 'image/png',
        'filename'  => 'chart.png',
        'payload'   => pngBytes(),
    ]);

    $post = new SocialPost();
    $post->principal_id = $principalId;
    $post->body = 'We shipped…';
    $post->status = SocialPost::STATUS_DRAFT;
    $post->save();

    $attachment = new SocialPostMedia();
    $attachment->post_id = $post->id;
    $attachment->media_asset_id = $asset->id;
    $attachment->position = 0;
    $attachment->save();

    $payload = SocialPostResource::toArray($post->fresh() ?? $post);

    expect($payload['media'])->toHaveCount(1)
        ->and($payload['media'][0]['media_asset_id'])->toBe($asset->id)
        ->and($payload['media'][0]['asset_url'])->toBe($asset->asset_url)
        ->and($payload['media'][0]['asset_url'])->toStartWith('/api/v1/assets/');
});

it('leaves the asset url empty when the asset row is gone', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = new SocialPost();
    $post->principal_id = $principalId;
    $post->body = 'We shipped…';
    $post->status = SocialPost::STATUS_DRAFT;
    $post->save();

    $attachment = new SocialPostMedia();
    $attachment->post_id = $post->id;
    $attachment->media_asset_id = uuid4();
    $attachment->position = 0;
    $attachment->save();

    $payload = SocialPostResource::toArray($post->fresh() ?? $post);

    expect($payload)->toHaveKey('media')
        ->and($payload['media'])->toHaveCount(1)
        ->and($payload['media'][0])->toHaveKey('asset_url')
        ->and($payload['media'][0]['asset_url'])->toBeNull()
        ->and($payload['media'][0]['mime_type'])->toBeNull()
        ->and($payload['media'][0]['filename'])->toBeNull()
        ->and($payload['body'])->toBe('We shipped…');
});

it('leaves the aggregated reach empty when no target reported one', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    // A LinkedIn member post: reactions only, no impressions and no reach.
    $post = postWithReadings($principalId, [
        ['platform' => 'linkedin', 'counters' => ['reach' => null, 'impressions' => null, 'likes' => 12, 'comments' => 3]],
    ]);

    $metrics = SocialPostResource::toArray($post)['metrics'];

    expect($metrics)->not->toBeNull()
        ->and($metrics)->toHaveKey('reach')
        ->and($metrics['reach'])->toBeNull()
        ->and($metrics['impressions'])->toBeNull()
        ->and($metrics['likes'])->toBe(12)
        ->and($metrics['comments'])->toBe(3);
});

it('adds up the targets that reported a reach and leaves the rest out', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = postWithReadings($principalId, [
        ['platform' => 'linkedin', 'counters' => ['reach' => 420, 'impressions' => 420, 'likes' => 20]],
        ['platform' => 'instagram', 'counters' => ['reach' => null, 'impressions' => null, 'likes' => 5]],
    ]);

    $metrics = SocialPostResource::toArray($post)['metrics'];

    expect($metrics['reach'])->toBe(420)
        ->and($metrics['impressions'])->toBe(420)
        ->and($metrics['likes'])->toBe(25)
        // Nobody reported a share, so there is no share figure to add up.
        ->and($metrics['shares'])->toBeNull();
});

it('keeps a reported zero a zero', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = postWithReadings($principalId, [
        ['platform' => 'instagram', 'counters' => ['reach' => 0, 'impressions' => 0, 'likes' => 0]],
    ]);

    $metrics = SocialPostResource::toArray($post)['metrics'];

    expect($metrics)->not->toBeNull()
        ->and($metrics)->toHaveKey('reach')
        ->and($metrics['reach'])->toBe(0)
        ->and($metrics['impressions'])->toBe(0)
        ->and($metrics['likes'])->toBe(0)
        // The counters nobody reported stay empty next to the reported zero.
        ->and($metrics['comments'])->toBeNull()
        ->and($metrics['shares'])->toBeNull();
});
