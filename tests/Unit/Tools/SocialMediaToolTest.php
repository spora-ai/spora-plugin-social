<?php

declare(strict_types=1);

use Spora\Plugins\Social\Tests\Support\SocialFixtures;
use Spora\Plugins\Social\Tools\SocialMediaTool;

it('lists the media library of the principal', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute(['action' => 'list_assets'], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['assets'])->toHaveCount(1)
        ->and($result->data['assets'][0]['id'])->toBe($asset->id)
        ->and($result->data['assets'][0]['mime_type'])->toBe(SocialFixtures::MIME_PNG)
        // The model reads the content, not the data — so the id it has
        // to attach has to be in the answer.
        ->and($result->content)->toContain($asset->id);
});

it('lists only the kind of media that was asked for', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $image = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);
    $document = createMediaAsset($principalId, [
        'mime_type'  => 'text/markdown',
        'filename'   => 'notes.md',
        'media_type' => 'document',
        'payload'    => '# hello',
    ]);

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute(
        ['action' => 'list_assets', 'media_kind' => 'image'],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeTrue()
        ->and($result->data['assets'])->toHaveCount(1)
        ->and($result->data['assets'][0]['id'])->toBe($image->id)
        ->and($result->content)->not->toContain($document->id);
});

it('refuses a media kind it does not know', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute(
        ['action' => 'list_assets', 'media_kind' => 'picture'],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('media_kind must be image, video, audio or document');
});

it('judges an asset by what the platform receives, not by its own mime', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute([
        'action'         => 'check_fit',
        'media_asset_id' => $asset->id,
        'channels'       => ['direct:instagram'],
    ], 1, null, principalContext($principalId));

    // Instagram takes no PNG, but the backend converts one before it
    // publishes. A check that judged the original would refuse a post
    // that goes out.
    expect($result->success)->toBeTrue()
        ->and($result->data['fits'])->toBeTrue()
        ->and($result->data['per_channel'][0]['channel'])->toBe('direct:instagram')
        ->and($result->data['per_channel'][0]['network'])->toBe('instagram')
        ->and($result->data['per_channel'][0]['mime_type'])->toBe(SocialFixtures::MIME_JPEG)
        ->and($result->data['per_channel'][0]['converted_from'])->toBe(SocialFixtures::MIME_PNG)
        ->and($result->content)->toContain('is converted to image/jpeg for direct:instagram');
});

it('names the remedy for a media the platform cannot take at all', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, ['mime_type' => 'text/markdown', 'payload' => '# hello']);

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute([
        'action'         => 'check_fit',
        'media_asset_id' => $asset->id,
        'channels'       => ['direct:instagram'],
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['fits'])->toBeFalse()
        ->and($result->data['per_channel'][0]['remedy'])->toBe('Convert to JPEG')
        ->and($result->content)->toContain('does not fit');
});

it('answers about the native channels when the agent names none', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute([
        'action'         => 'check_fit',
        'media_asset_id' => $asset->id,
    ], 1, null, principalContext($principalId));

    // The three the plugin publishes on itself, and not the fifteen: the
    // aggregator's `zernio:youtube` takes no image, so the default
    // answer for an ordinary image would flip from "fits" to "does not
    // fit".
    expect($result->success)->toBeTrue()
        ->and($result->data['fits'])->toBeTrue()
        ->and($result->data['per_channel'])->toHaveCount(3)
        ->and(array_column($result->data['per_channel'], 'channel'))
        ->toBe(['direct:linkedin', 'direct:facebook', 'direct:instagram']);
});

it('still answers the old platforms argument, for one release', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute([
        'action'         => 'check_fit',
        'media_asset_id' => $asset->id,
        'platforms'      => ['instagram'],
    ], 1, null, principalContext($principalId));

    // A bare network name in the old argument means the plugin's own
    // route to it — the route it meant before the rename.
    expect($result->success)->toBeTrue()
        ->and($result->data['per_channel'])->toHaveCount(1)
        ->and($result->data['per_channel'][0]['channel'])->toBe('direct:instagram')
        // And the old answer key is still there.
        ->and($result->data['per_platform'])->toHaveCount(1)
        ->and($result->data['per_platform'][0]['platform'])->toBe('instagram');
});

it('prepares an asset for instagram', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute([
        'action'         => 'prepare',
        'media_asset_id' => $asset->id,
        'platform'       => 'instagram',
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['format'])->toBe('format-jpeg')
        ->and($result->data['mime_type'])->toBe('image/jpeg')
        ->and($result->data['url'])->toStartWith('https://spora.test/api/v1/public/media/')
        // The derivative's id and the URL it is served under are what
        // the agent has to know, and the model reads the content.
        ->and($result->content)->toContain((string) $result->data['media_asset_id'])
        ->and($result->content)->toContain((string) $result->data['url'])
        ->and($result->content)->toContain($asset->id);
});

it('attaches an asset to a post', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');
    $asset = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    $service = postService();
    $tool = new SocialMediaTool(mediaPipeline(), $service);

    $created = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
    ]);

    $result = $tool->execute([
        'action'         => 'attach',
        'media_asset_id' => $asset->id,
        'post_id'        => $created->id,
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['media'])->toHaveCount(1)
        ->and($result->data['post']['media'][0]['media_asset_id'])->toBe($asset->id);
});

it('refuses a second attachment, because a post carries one', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');
    $first = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);
    $second = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    $service = postService();
    $tool = new SocialMediaTool(mediaPipeline(), $service);

    $created = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
        'media'       => [['media_asset_id' => $first->id, 'position' => 0]],
    ]);

    $result = $tool->execute([
        'action'         => 'attach',
        'media_asset_id' => $second->id,
        'post_id'        => $created->id,
    ], 1, null, principalContext($principalId));

    // The publish sends one file, so a second is a file the operator
    // believes is going out and is not.
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('one attachment');
});

it('detaches an asset from a post', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');
    $asset = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    $service = postService();
    $tool = new SocialMediaTool(mediaPipeline(), $service);

    $created = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
        'media'       => [['media_asset_id' => $asset->id, 'position' => 0]],
    ]);

    $result = $tool->execute([
        'action'         => 'detach',
        'media_asset_id' => $asset->id,
        'post_id'        => $created->id,
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['media'])->toHaveCount(0);
});

it('answers with an error for an asset of another principal', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $otherId = createUserPrincipal(bootAuth(bootAuthLayer(), 'other@example.test'));

    $asset = createMediaAsset($otherId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute([
        'action'         => 'check_fit',
        'media_asset_id' => $asset->id,
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('was not found for this principal');
});

it('answers with an error for an unknown action instead of throwing', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute(['action' => 'nonsense'], 1, null, principalContext($principalId));

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Invalid action 'nonsense'");
});
