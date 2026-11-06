<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Service\PlatformFit;

/**
 * The aggregator's rows in the rules table.
 *
 * The table is a copy of Zernio's own documentation, one row per
 * platform page. Zernio's rows for LinkedIn, Facebook and Instagram are
 * **Zernio's** limits, not the plugin's, and they differ — so a row of
 * its own, not an alias of the native one.
 */
it('has a row for every network the aggregator reaches', function () {
    $networks = array_map(
        static fn(SocialChannel $channel): string => $channel->network,
        array_filter(PlatformFit::channels(), static fn(SocialChannel $channel): bool => !$channel->isDirect()),
    );

    expect($networks)->toContain('bluesky', 'threads', 'tiktok', 'youtube', 'pinterest', 'reddit', 'twitter')
        ->and($networks)->toContain('instagram', 'facebook', 'linkedin', 'googlebusiness', 'snapchat');
});

it('does not alias the aggregator linkedin to the native one', function () {
    $native = PlatformFit::rule(SocialChannel::direct('linkedin'));
    $aggregator = PlatformFit::rule(SocialChannel::tryFrom('zernio', 'linkedin'));

    // Zernio allows several images where the native path allows one
    // attachment, and a video where the native path allows none.
    expect($aggregator['mime_types'])->toContain('video/mp4')
        ->and($native['mime_types'])->not->toContain('video/mp4')
        ->and($aggregator['max_bytes'])->not->toBe($native['max_bytes']);
});

it('lets a row answer its own remedy', function () {
    $bluesky = PlatformFit::rule(SocialChannel::tryFrom('zernio', 'bluesky'));

    // The remedy rides on the row, so a network the plugin does not
    // reach natively still answers with the conversion it needs.
    expect($bluesky['media_remedy'])->toBe(PlatformFit::REMEDY_CONVERT_JPEG)
        ->and($bluesky['video_remedy'])->toBe(PlatformFit::REMEDY_CONVERT_VIDEO);

    $fit = PlatformFit::check(SocialChannel::tryFrom('zernio', 'bluesky'), 'video/quicktime', 1024);

    expect($fit['remedy'])->toBe(PlatformFit::REMEDY_CONVERT_VIDEO)
        ->and($fit['reasons'][0])->toContain('Bluesky does not accept video/quicktime');
});

it('says the aggregator fetches the media itself', function () {
    // Zernio copies the file from the URL the plugin sends, so the URL
    // has to be publicly reachable — the native path serves it.
    foreach (PlatformFit::channels() as $channel) {
        if ($channel->isDirect()) {
            continue;
        }

        expect(PlatformFit::rule($channel)['fetches_media'])->toBeTrue();
    }
});

it('does not count the characters of a network that counts them its own way', function () {
    $fit = PlatformFit::checkBody(SocialChannel::tryFrom('zernio', 'twitter'), str_repeat('a', 500));

    // X counts a URL as 23 characters and an emoji as 2, so the number
    // of characters in the body is not the number X counts. A number the
    // plugin cannot compute is worse than no number.
    expect($fit['fits'])->toBeTrue()
        ->and($fit['reasons'])->toBe([]);

    $rule = PlatformFit::rule(SocialChannel::tryFrom('zernio', 'twitter'));

    expect($rule['counting_note'])->not->toBeNull()
        ->and($rule['counting_note'])->toContain('23');
});

it('refuses an over-limit body on a network whose count it can compute', function () {
    $fit = PlatformFit::checkBody(SocialChannel::tryFrom('zernio', 'bluesky'), str_repeat('a', 301));

    expect($fit['fits'])->toBeFalse()
        ->and($fit['reasons'][0])->toContain('Over the limit')
        ->and($fit['reasons'][0])->toContain('Bluesky');
});
