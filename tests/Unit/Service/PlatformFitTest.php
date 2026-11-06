<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Service\PlatformFit;

it('keys the rules by the channel of each network', function () {
    $expected = array_map(
        static fn(SocialPlatform $platform): string => SocialChannel::direct($platform->value)->key(),
        SocialPlatform::cases(),
    );

    // The aggregator's rows follow the native ones: they are the same
    // networks reached through somebody else's API.
    expect(array_slice(array_keys(PlatformFit::rules()), 0, count($expected)))->toBe($expected)
        ->and(array_keys(PlatformFit::rules()))->toContain('zernio:bluesky');
});

it('accepts a jpeg on linkedin', function () {
    $fit = PlatformFit::check(SocialChannel::direct('linkedin'), 'image/jpeg', 1024);

    expect($fit['fits'])->toBeTrue()
        ->and($fit['reasons'])->toBe([])
        ->and($fit['remedy'])->toBeNull();
});

it('names the remedy for a png on instagram', function () {
    $fit = PlatformFit::check(SocialChannel::direct('instagram'), 'image/png', 1024);

    expect($fit['fits'])->toBeFalse()
        ->and($fit['reasons'][0])->toContain('does not accept image/png')
        ->and($fit['remedy'])->toBe('Convert to JPEG');
});

it('says instagram needs media when there is none', function () {
    $fit = PlatformFit::check(SocialChannel::direct('instagram'), null, null);

    expect($fit['fits'])->toBeFalse()
        ->and($fit['reasons'][0])->toContain('without media')
        ->and($fit['remedy'])->toBe('Attach a JPEG image or an MP4 video');
});

it('accepts a post without media on linkedin and facebook', function () {
    expect(PlatformFit::check(SocialChannel::direct('linkedin'), null, null)['fits'])->toBeTrue()
        ->and(PlatformFit::check(SocialChannel::direct('facebook'), null, null)['fits'])->toBeTrue();
});

it('names the remedy for a file over the cap', function () {
    $fit = PlatformFit::check(SocialChannel::direct('linkedin'), 'image/jpeg', 9 * 1024 * 1024);

    expect($fit['fits'])->toBeFalse()
        ->and($fit['reasons'][0])->toContain('at most 8 MB')
        ->and($fit['remedy'])->toBe('Compress');
});

it('accepts facebook bmp and mp4, which linkedin does not', function () {
    expect(PlatformFit::check(SocialChannel::direct('facebook'), 'image/bmp', 1024)['fits'])->toBeTrue()
        ->and(PlatformFit::check(SocialChannel::direct('facebook'), 'video/mp4', 1024)['fits'])->toBeTrue()
        ->and(PlatformFit::check(SocialChannel::direct('linkedin'), 'image/bmp', 1024)['fits'])->toBeFalse();
});

it('names the remedy for a video on instagram', function () {
    $fit = PlatformFit::check(SocialChannel::direct('instagram'), 'video/quicktime', 1024);

    expect($fit['remedy'])->toBe('Convert the file to MP4');
});

it('answers the same limit for the channel as for the network', function () {
    // The rules are keyed by the channel now, and the direct route to a
    // network carries the network's own limits.
    expect(PlatformFit::characterLimit(SocialChannel::direct('linkedin')))->toBe(3000)
        ->and(PlatformFit::characterLimit(SocialChannel::direct('facebook')))->toBe(63206)
        ->and(PlatformFit::characterLimit(SocialChannel::direct('instagram')))->toBe(2200);
});

it('says the body is over the limit, and names the network', function () {
    $fit = PlatformFit::checkBody(SocialChannel::direct('instagram'), str_repeat('a', 2201));

    expect($fit['fits'])->toBeFalse()
        ->and($fit['reasons'][0])->toContain('Over the limit')
        ->and($fit['reasons'][0])->toContain('2200')
        ->and($fit['reasons'][0])->toContain('Instagram')
        ->and($fit['remedy'])->toBe(PlatformFit::REMEDY_SHORTEN);
});

it('says there are no rules for a channel it does not know', function () {
    expect(fn() => PlatformFit::rule(SocialChannel::tryFrom('zernio', 'myspace')))
        ->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'There are no rules for the channel zernio:myspace.');
});

it('serialises the rules for the frontend', function () {
    $rules = PlatformFit::apiRules();

    expect($rules)->toHaveKeys(['direct:linkedin', 'direct:facebook', 'direct:instagram', 'zernio:bluesky']);

    foreach (PlatformFit::channels() as $channel) {
        $rule = PlatformFit::rule($channel);

        expect($rules[$channel->key()]['media_required'])->toBe($rule['media_required'])
            ->and($rules[$channel->key()]['mime_types'])->toBe($rule['mime_types'])
            ->and($rules[$channel->key()]['max_bytes'])->toBe($rule['max_bytes'])
            ->and($rules[$channel->key()]['max_text'])->toBe($rule['max_characters'])
            ->and($rules[$channel->key()]['media_note'])->toContain($rule['media']);
    }
});

it('serialises the remedies the frontend shows', function () {
    $rules = PlatformFit::apiRules();

    expect($rules['direct:instagram']['media_remedy'])->toBe(PlatformFit::REMEDY_CONVERT_JPEG)
        ->and($rules['direct:instagram']['video_remedy'])->toBe(PlatformFit::REMEDY_CONVERT_VIDEO)
        ->and($rules['direct:instagram']['size_remedy'])->toBe(PlatformFit::REMEDY_COMPRESS);
});

it('puts the hashtags under the body', function () {
    expect(PlatformFit::composeText('Hi', ['a', 'b']))->toBe("Hi\n\n#a #b");
});

it('leaves the body alone when the post carries no hashtags', function () {
    expect(PlatformFit::composeText('Hi', []))->toBe('Hi');
});

it('names the characters a network shows before it hides the rest', function () {
    expect(PlatformFit::rule(SocialChannel::direct('linkedin'))['visible_chars'])->toBe(200)
        ->and(PlatformFit::rule(SocialChannel::direct('facebook'))['visible_chars'])->toBe(125)
        ->and(PlatformFit::rule(SocialChannel::direct('instagram'))['visible_chars'])->toBe(125);
});

it('tells the frontend how many characters a network shows', function () {
    $rules = PlatformFit::apiRules();

    expect($rules['direct:linkedin']['visible_chars'])->toBe(200)
        ->and($rules['direct:facebook']['visible_chars'])->toBe(125)
        ->and($rules['direct:instagram']['visible_chars'])->toBe(125);
});
