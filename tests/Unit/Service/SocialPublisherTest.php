<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialPostTarget;

/**
 * Taking a published post back down.
 *
 * The delete is per target, because the publish was: a post that reached
 * LinkedIn and not Instagram takes down the LinkedIn one.
 */
it('takes the post back down on every network that published it', function () {
    $publisher = postPublisher([
        'linkedin' => [
            ['body' => '{"id":"urn:li:share:9"}'],
            ['body' => '', 'status' => 204],
        ],
        'facebook' => [
            ['body' => '{"id":"111_222"}'],
            ['body' => '{"success":true}'],
        ],
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');
    $facebook = publishableAccount($principalId, 'facebook', 'page');

    $post = $service->create($principalId, [
        'body'        => 'We shipped…',
        'account_ids' => [$linkedIn->id, $facebook->id],
    ]);

    $post = $publisher->publish((int) $post->id, $principalId);
    expect($post->status)->toBe('published');

    $post = $publisher->delete((int) $post->id, $principalId);

    $targets = $post->targets()->orderBy('id')->get();

    expect($targets)->toHaveCount(2)
        ->and($targets[0]->status)->toBe(SocialPostTarget::STATUS_DELETED)
        ->and($targets[0]->error)->toBeNull()
        ->and($targets[1]->status)->toBe(SocialPostTarget::STATUS_DELETED)
        ->and($targets[1]->error)->toBeNull()
        // Nothing is on a network any more, and nothing failed either.
        ->and($post->status)->toBe('cancelled');
});

it('leaves an instagram target published, because instagram cannot delete', function () {
    $publisher = postPublisher([
        // The post carries an image, so LinkedIn uploads it before it
        // posts: the upload slot, the bytes, the post, the delete.
        'linkedin' => [
            ['body' => '{"value":{"uploadUrl":"https://www.linkedin.com/dms-uploads/x/0","image":"urn:li:image:X"}}'],
            ['body' => '', 'status' => 200],
            ['body' => '{"id":"urn:li:share:9"}', 'status' => 201],
            ['body' => '', 'status' => 204],
        ],
        'instagram' => [
            ['body' => '{"data":[{"quota_usage":0,"config":{"quota_total":50}}]}'],
            ['body' => '{"id":"17900000000000001"}'],
            ['body' => '{"id":"17900000000000002"}'],
        ],
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');
    $instagram = publishableAccount($principalId, 'instagram', 'business');

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/jpeg', 'payload' => jpegBytes()]);

    $post = $service->create($principalId, [
        'body'        => 'We shipped…',
        'account_ids' => [$linkedIn->id, $instagram->id],
        'media'       => [['media_asset_id' => $asset->id, 'position' => 0]],
    ]);

    $post = $publisher->publish((int) $post->id, $principalId);
    expect($post->status)->toBe('published');

    $post = $publisher->delete((int) $post->id, $principalId);

    $targets = $post->targets()->orderBy('id')->get();

    // The LinkedIn post is gone; the Instagram one is still there, and the
    // target says why.
    expect($targets[0]->status)->toBe(SocialPostTarget::STATUS_DELETED)
        ->and($targets[1]->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($targets[1]->error)->toContain('cannot take a published post back down')
        ->and($post->status)->toBe('partial');
});

it('keeps the container id of an instagram publish, so the container can be asked about', function () {
    $publisher = postPublisher([
        'instagram' => [
            ['body' => '{"data":[{"quota_usage":0,"config":{"quota_total":50}}]}'],
            ['body' => '{"id":"17900000000000001"}'],
            ['body' => '{"id":"17900000000000002"}'],
        ],
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $instagram = publishableAccount($principalId, 'instagram', 'business');
    $asset = createMediaAsset($principalId, ['mime_type' => 'image/jpeg', 'payload' => jpegBytes()]);

    $post = $service->create($principalId, [
        'body'        => 'We shipped…',
        'account_ids' => [$instagram->id],
        'media'       => [['media_asset_id' => $asset->id, 'position' => 0]],
    ]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $target = $post->targets()->orderBy('id')->first();

    // The container is what a publish that half-finished is checked
    // against: `GET /{container}?fields=status_code`.
    expect($target?->external_id)->toBe('17900000000000002')
        ->and($target?->container_id)->toBe('17900000000000001');
});

it('refuses to publish a post that carries a second attachment', function () {
    $publisher = postPublisher([
        'linkedin' => [['body' => '{"id":"urn:li:share:9"}']],
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');

    $first = createMediaAsset($principalId, ['mime_type' => 'image/png', 'payload' => pngBytes()]);
    $second = createMediaAsset($principalId, ['mime_type' => 'image/png', 'payload' => pngBytes()]);

    $post = $service->create($principalId, [
        'body'        => 'We shipped…',
        'account_ids' => [$linkedIn->id],
        'media'       => [['media_asset_id' => $first->id, 'position' => 0]],
    ]);

    // The row is written directly, the way an older install or a direct
    // database edit would leave it.
    $attachment = new Spora\Plugins\Social\Model\SocialPostMedia();
    $attachment->post_id = $post->id;
    $attachment->media_asset_id = $second->id;
    $attachment->position = 1;
    $attachment->save();

    $post = $publisher->publish((int) $post->id, $principalId);

    $target = $post->targets()->orderBy('id')->first();

    // The publish sends one file. A second would be a file the operator
    // believes is going out and is not.
    expect($target?->status)->toBe('failed')
        ->and($target?->error)->toContain('one attachment');
});
