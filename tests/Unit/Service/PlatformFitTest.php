<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Service\PlatformFit;

it('accepts a jpeg on linkedin', function () {
    $fit = PlatformFit::check(SocialPlatform::LinkedIn, 'image/jpeg', 1024);

    expect($fit['fits'])->toBeTrue()
        ->and($fit['reasons'])->toBe([])
        ->and($fit['remedy'])->toBeNull();
});

it('names the remedy for a png on instagram', function () {
    $fit = PlatformFit::check(SocialPlatform::Instagram, 'image/png', 1024);

    expect($fit['fits'])->toBeFalse()
        ->and($fit['reasons'][0])->toContain('does not accept image/png')
        ->and($fit['remedy'])->toBe('Convert to JPEG');
});

it('says instagram needs media when there is none', function () {
    $fit = PlatformFit::check(SocialPlatform::Instagram, null, null);

    expect($fit['fits'])->toBeFalse()
        ->and($fit['reasons'][0])->toContain('without media')
        ->and($fit['remedy'])->toBe('Attach a JPEG image or an MP4 video');
});

it('accepts a post without media on linkedin and facebook', function () {
    expect(PlatformFit::check(SocialPlatform::LinkedIn, null, null)['fits'])->toBeTrue()
        ->and(PlatformFit::check(SocialPlatform::Facebook, null, null)['fits'])->toBeTrue();
});

it('names the remedy for a file over the cap', function () {
    $fit = PlatformFit::check(SocialPlatform::LinkedIn, 'image/jpeg', 9 * 1024 * 1024);

    expect($fit['fits'])->toBeFalse()
        ->and($fit['reasons'][0])->toContain('at most 8 MB')
        ->and($fit['remedy'])->toBe('Compress');
});

it('accepts facebook bmp and mp4, which linkedin does not', function () {
    expect(PlatformFit::check(SocialPlatform::Facebook, 'image/bmp', 1024)['fits'])->toBeTrue()
        ->and(PlatformFit::check(SocialPlatform::Facebook, 'video/mp4', 1024)['fits'])->toBeTrue()
        ->and(PlatformFit::check(SocialPlatform::LinkedIn, 'image/bmp', 1024)['fits'])->toBeFalse();
});

it('names the remedy for a video on instagram', function () {
    $fit = PlatformFit::check(SocialPlatform::Instagram, 'video/quicktime', 1024);

    expect($fit['remedy'])->toBe('Convert the file to MP4');
});

it('serialises the rules for the frontend', function () {
    $rules = PlatformFit::apiRules();

    expect($rules)->toHaveKeys(['linkedin', 'facebook', 'instagram']);

    foreach (SocialPlatform::cases() as $platform) {
        $rule = PlatformFit::rule($platform);

        expect($rules[$platform->value]['media_required'])->toBe($rule['media_required'])
            ->and($rules[$platform->value]['mime_types'])->toBe($rule['mime_types'])
            ->and($rules[$platform->value]['max_bytes'])->toBe($rule['max_bytes'])
            ->and($rules[$platform->value]['max_text'])->toBe($rule['max_characters'])
            ->and($rules[$platform->value]['media_note'])->toContain($rule['media']);
    }
});

it('serialises the remedies the frontend shows', function () {
    $rules = PlatformFit::apiRules();

    expect($rules['instagram']['media_remedy'])->toBe(PlatformFit::REMEDY_CONVERT_JPEG)
        ->and($rules['instagram']['video_remedy'])->toBe(PlatformFit::REMEDY_CONVERT_VIDEO)
        ->and($rules['instagram']['size_remedy'])->toBe(PlatformFit::REMEDY_COMPRESS);
});
