<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;

it('prepares a jpeg derivative and a public url for instagram', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, [
        'mime_type' => SocialFixtures::MIME_PNG,
        'filename'  => 'chart.png',
        'payload'   => pngBytes(),
    ]);

    $prepared = mediaPipeline()->prepare($asset, SocialPlatform::Instagram);

    expect($prepared->mimeType)->toBe(SocialFixtures::MIME_JPEG)
        ->and($prepared->derivativeFormat)->toBe('format-jpeg')
        ->and($prepared->mediaAssetId)->not->toBe($asset->id)
        ->and($prepared->publicUrl)->toStartWith('https://spora.test/api/v1/public/media/')
        ->and($prepared->publicUrl)->toContain('?token=');

    $derivative = Spora\Models\MediaAsset::query()->find($prepared->mediaAssetId);

    expect($derivative)->not->toBeNull()
        ->and($derivative?->mime_type)->toBe(SocialFixtures::MIME_JPEG)
        ->and($derivative?->principal_id)->toBe($principalId)
        ->and($derivative?->public_access_token)->not->toBeNull();

    // The original keeps its own bytes; the derivative is a new row.
    expect($asset->fresh()?->mime_type)->toBe(SocialFixtures::MIME_PNG);
});

it('serves a jpeg that is already a jpeg without converting it again', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, [
        'mime_type' => SocialFixtures::MIME_JPEG,
        'filename'  => 'photo.jpg',
        'payload'   => pngBytes(),
    ]);

    $prepared = mediaPipeline()->prepare($asset, SocialPlatform::Instagram);

    expect($prepared->mimeType)->toBe(SocialFixtures::MIME_JPEG)
        ->and($prepared->derivativeFormat)->toBeNull()
        ->and($prepared->mediaAssetId)->toBe($asset->id);
});

it('hands linkedin the bytes rather than a url', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, [
        'mime_type' => SocialFixtures::MIME_PNG,
        'filename'  => 'chart.png',
        'payload'   => pngBytes(),
    ]);

    $prepared = mediaPipeline()->prepare($asset, SocialPlatform::LinkedIn);

    expect($prepared->bytes)->toBe(pngBytes())
        ->and($prepared->publicUrl)->toBeNull()
        ->and($prepared->mimeType)->toBe(SocialFixtures::MIME_PNG);
});

it('refuses an asset of another principal', function () {
    $ownerId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $otherId = createUserPrincipal(bootAuth(bootAuthLayer(), 'other@example.test'));

    $asset = createMediaAsset($ownerId, ['mime_type' => SocialFixtures::MIME_PNG, 'payload' => pngBytes()]);

    expect(fn() => mediaPipeline()->findAsset((string) $asset->id, $otherId))
        ->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'was not found for this principal');
});
