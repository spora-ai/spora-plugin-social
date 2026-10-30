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
        ->and($result->data['assets'][0]['mime_type'])->toBe(SocialFixtures::MIME_PNG);
});

it('checks an asset against a platform and names the remedy', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    $tool = new SocialMediaTool(mediaPipeline(), postService());

    $result = $tool->execute([
        'action'         => 'check_fit',
        'media_asset_id' => $asset->id,
        'platforms'      => ['instagram'],
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['fits'])->toBeFalse()
        ->and($result->data['per_platform'][0]['platform'])->toBe('instagram')
        ->and($result->data['per_platform'][0]['remedy'])->toBe('Convert to JPEG');
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
        ->and($result->data['url'])->toStartWith('https://spora.test/api/v1/public/media/');
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
