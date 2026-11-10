<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Service\PlatformFit;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;

it('prepares a jpeg derivative and a public url for instagram', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, [
        'mime_type' => SocialFixtures::MIME_PNG,
        'filename'  => 'chart.png',
        'payload'   => pngBytes(),
    ]);

    $prepared = mediaPipeline()->prepare($asset, SocialChannel::direct('instagram'));

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

    $prepared = mediaPipeline()->prepare($asset, SocialChannel::direct('instagram'));

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

    $prepared = mediaPipeline()->prepare($asset, SocialChannel::direct('linkedin'));

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

it('converts a webp into a jpeg for linkedin, which takes no webp', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, [
        'mime_type' => SocialFixtures::MIME_WEBP,
        'filename'  => 'chart.webp',
        'payload'   => webpBytes(),
    ]);

    $prepared = mediaPipeline()->prepare($asset, SocialChannel::direct('linkedin'));

    expect($prepared->mimeType)->toBe(SocialFixtures::MIME_JPEG)
        ->and($prepared->derivativeFormat)->toBe('format-jpeg')
        ->and($prepared->mediaAssetId)->not->toBe($asset->id)
        ->and($prepared->bytes)->not->toBe('');

    // The original keeps its own bytes; the derivative is a new row.
    expect($asset->fresh()?->mime_type)->toBe(SocialFixtures::MIME_WEBP);
});

it('leaves a png alone for linkedin, which takes one', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, [
        'mime_type' => SocialFixtures::MIME_PNG,
        'filename'  => 'chart.png',
        'payload'   => pngBytes(),
    ]);

    $prepared = mediaPipeline()->prepare($asset, SocialChannel::direct('linkedin'));

    // The conversion is for a file the network will not take, not a
    // preference: a PNG is what LinkedIn takes, so it is what goes out.
    expect($prepared->mimeType)->toBe(SocialFixtures::MIME_PNG)
        ->and($prepared->derivativeFormat)->toBeNull()
        ->and($prepared->mediaAssetId)->toBe($asset->id);
});

it('judges the fit on the converted file, so a webp fits linkedin', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, [
        'mime_type' => SocialFixtures::MIME_WEBP,
        'filename'  => 'chart.webp',
        'payload'   => webpBytes(),
    ]);

    $prepared = mediaPipeline()->prepareForFit($asset, SocialChannel::direct('linkedin'));

    $fit = PlatformFit::check(SocialChannel::direct('linkedin'), $prepared->mimeType, $prepared->sizeBytes);

    // The operator is not told to convert a file the plugin has already
    // converted for them.
    expect($fit['fits'])->toBeTrue()
        ->and($fit['reasons'])->toBe([])
        ->and($prepared->mimeType)->toBe(SocialFixtures::MIME_JPEG);
});

it('leaves the aggregator’s channels alone', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, [
        'mime_type' => SocialFixtures::MIME_WEBP,
        'filename'  => 'chart.webp',
        'payload'   => webpBytes(),
    ]);

    // One post goes to the aggregator as one file for a group of networks,
    // so a file converted for one of them would not fit the others.
    $prepared = mediaPipeline()->prepareForFit($asset, SocialChannel::fromKey('zernio:linkedin'));

    expect($prepared->mimeType)->toBe(SocialFixtures::MIME_WEBP)
        ->and($prepared->derivativeFormat)->toBeNull();
});

it('leaves a file the archive cannot convert alone', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $asset = createMediaAsset($principalId, [
        'mime_type' => 'image/svg+xml',
        'filename'  => 'chart.svg',
        'payload'   => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    ]);

    $prepared = mediaPipeline()->prepareForFit($asset, SocialChannel::direct('linkedin'));

    // No producer reads an SVG, so the file is what goes out — and the fit
    // check says so rather than pretending it fits.
    expect($prepared->mimeType)->toBe('image/svg+xml')
        ->and($prepared->derivativeFormat)->toBeNull();

    $fit = PlatformFit::check(SocialChannel::direct('linkedin'), $prepared->mimeType, $prepared->sizeBytes);

    expect($fit['fits'])->toBeFalse();
});
