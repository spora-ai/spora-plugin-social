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

/**
 * The platforms a post can target, as Zernio's own specification names
 * them.
 *
 * `PlatformTarget.platform` in `https://zernio.com/openapi.json` lists
 * them, and that list is the authority: the table carries a row for each
 * of them and for nothing else, so a network Zernio adds is one the
 * plugin can publish to and one Zernio drops is one it cannot.
 *
 * @return list<string>
 */
function zernioPublishablePlatforms(): array
{
    $platforms = [
        'twitter',
        'threads',
        'instagram',
        'youtube',
        'facebook',
        'linkedin',
        'pinterest',
        'reddit',
        'tiktok',
        'bluesky',
        'googlebusiness',
        'telegram',
    ];

    sort($platforms);

    return $platforms;
}

/**
 * The aggregator's rows, by the network they are for.
 *
 * @return list<string>
 */
function zernioRuleNetworks(): array
{
    $networks = array_map(
        static fn(SocialChannel $channel): string => $channel->network,
        array_filter(PlatformFit::channels(), static fn(SocialChannel $channel): bool => !$channel->isDirect()),
    );

    sort($networks);

    return $networks;
}

it('has a row for every platform Zernio can publish to, and for no other', function () {
    expect(zernioRuleNetworks())->toBe(zernioPublishablePlatforms());
});

it('offers no network a post cannot target', function () {
    $rules = PlatformFit::rules();

    // An account may exist on a network a post cannot name — Snapchat is
    // a closed beta, Discord and Slack are messaging networks and the
    // ads platforms are no post at all — and a row for one would offer a
    // network the publish can only fail on.
    foreach (['snapchat', 'discord', 'slack', 'whatsapp', 'shopify', 'wordpress', 'metaads', 'googleads'] as $network) {
        expect($rules)->not->toHaveKey('zernio:' . $network);
    }
});

it("carries Telegram's own limits", function () {
    $telegram = PlatformFit::rule(SocialChannel::tryFrom('zernio', 'telegram'));

    // A Telegram post is a message: 4,096 characters, an image up to
    // 10 MB, a video up to 50 MB and ten items in an album.
    expect($telegram['label'])->toBe('Telegram')
        ->and($telegram['max_characters'])->toBe(4096)
        ->and($telegram['max_bytes'])->toBe(50 * 1024 * 1024)
        ->and($telegram['media_required'])->toBeFalse()
        ->and($telegram['mime_types'])->toContain('image/webp')
        ->and($telegram['mime_types'])->toContain('video/quicktime')
        ->and($telegram['media'])->toContain('10 MB')
        ->and($telegram['media'])->toContain('50 MB');
});

it('does not promise an album the plugin will not send', function () {
    $telegram = PlatformFit::rule(SocialChannel::tryFrom('zernio', 'telegram'));

    // Zernio's own limit is an album of ten, and the plugin's rule of
    // one attachment per post holds on every channel. The row carries
    // both, so the operator is never told the plugin sends ten files.
    expect($telegram['media'])->toContain('album')
        ->and($telegram['media'])->toContain('one attachment')
        ->and($telegram['media_count'])->toBe(PlatformFit::MEDIA_PER_POST);
});

it('names the networks the aggregator reaches in the skill', function () {
    $prose = socialSkill()['body'];

    // The skill is the agent's only description of what the plugin can
    // reach, and a network it names is a network the agent will try.
    expect($prose)->toContain('Telegram')
        ->and($prose)->not->toContain('Snapchat');
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

it('refuses an over-limit body on Telegram', function () {
    // 4,096 characters is the message limit, and the row carries
    // Telegram's own number rather than another network's.
    $fit = PlatformFit::checkBody(SocialChannel::tryFrom('zernio', 'telegram'), str_repeat('a', 4097));

    expect($fit['fits'])->toBeFalse()
        ->and($fit['reasons'][0])->toContain('Over the limit')
        ->and($fit['reasons'][0])->toContain('Telegram');
});
