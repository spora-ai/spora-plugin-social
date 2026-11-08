<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialChannel;

/**
 * The channel: the network, and the route to it.
 *
 * The pair is the identity, so the key carries both — and the same
 * network on two routes is two channels, never one.
 */
it('names a channel by its route and its network', function () {
    $channel = SocialChannel::tryFrom('zernio', 'bluesky');

    expect($channel?->key())->toBe('zernio:bluesky')
        ->and($channel?->network)->toBe('bluesky')
        ->and($channel?->via)->toBe('zernio');
});

it('trims and lower-cases both parts of a channel', function () {
    $channel = SocialChannel::tryFrom('  Zernio ', ' BlueSky ');

    expect($channel?->key())->toBe('zernio:bluesky');
});

it('names the plugin\'s own route directly', function () {
    $channel = SocialChannel::direct('LinkedIn');

    expect($channel->key())->toBe('direct:linkedin')
        ->and($channel->isDirect())->toBeTrue()
        ->and($channel->network)->toBe('linkedin');
});

it('names no channel without a route or without a network', function () {
    expect(SocialChannel::tryFrom('', 'bluesky'))->toBeNull()
        ->and(SocialChannel::tryFrom('zernio', ''))->toBeNull()
        ->and(SocialChannel::tryFrom('  ', '  '))->toBeNull();
});

it('reads a bare network name as the plugin\'s own route', function () {
    expect(SocialChannel::fromKey('linkedin')?->key())->toBe('direct:linkedin')
        ->and(SocialChannel::fromKey(' DIRECT:LINKEDIN ')?->key())->toBe('direct:linkedin')
        ->and(SocialChannel::fromKey('zernio:bluesky')?->key())->toBe('zernio:bluesky')
        ->and(SocialChannel::fromKey('  '))->toBeNull();
});

it('is not the plugin\'s own route when another route reaches the network', function () {
    expect(SocialChannel::tryFrom('zernio', 'linkedin')?->isDirect())->toBeFalse();
});
