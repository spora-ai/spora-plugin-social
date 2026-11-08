<?php

declare(strict_types=1);

use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Publish\FacebookAdapter;
use Spora\Plugins\Social\Publish\InstagramAdapter;
use Spora\Plugins\Social\Publish\LinkedInAdapter;
use Spora\Plugins\Social\Publish\PublisherRegistry;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The registry: the adapter of a channel.
 *
 * The three adapters are the plugin's own route to the networks, so
 * they answer for the direct channel of each.
 */
function publisherRegistry(): PublisherRegistry
{
    $api = platformApi(new MockHttpClient());

    return new PublisherRegistry(
        new LinkedInAdapter($api),
        new FacebookAdapter($api),
        new InstagramAdapter($api),
    );
}

it('resolves the adapter of a direct channel', function () {
    $registry = publisherRegistry();

    expect($registry->for(SocialChannel::direct('linkedin')))->toBeInstanceOf(LinkedInAdapter::class)
        ->and($registry->for(SocialChannel::direct('facebook')))->toBeInstanceOf(FacebookAdapter::class)
        ->and($registry->for(SocialChannel::direct('instagram')))->toBeInstanceOf(InstagramAdapter::class);
});

it('refuses a channel nothing can publish on', function () {
    $registry = publisherRegistry();

    // The same network through another route is another channel, and
    // nothing publishes on it yet.
    expect(fn() => $registry->for(SocialChannel::tryFrom('zernio', 'linkedin')))
        ->toThrow(SocialException::class, 'This target\'s channel is one nothing can publish.');
});
