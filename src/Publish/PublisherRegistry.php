<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Model\SocialPlatform;

/**
 * The publishing adapters, resolved by channel.
 *
 * The three adapters are the plugin's own route to the networks, so
 * they are keyed by the direct channel of each.
 */
final class PublisherRegistry
{
    /**
     * @var array<string, PublisherInterface>
     */
    private readonly array $publishers;

    public function __construct(
        LinkedInAdapter $linkedIn,
        FacebookAdapter $facebook,
        InstagramAdapter $instagram,
    ) {
        $this->publishers = [
            SocialChannel::direct(SocialPlatform::LinkedIn->value)->key()  => $linkedIn,
            SocialChannel::direct(SocialPlatform::Facebook->value)->key()  => $facebook,
            SocialChannel::direct(SocialPlatform::Instagram->value)->key() => $instagram,
        ];
    }

    /**
     * The adapter that publishes on a channel.
     *
     * A channel without an adapter is one nothing can publish: the
     * network is not one the plugin reaches, or it is reached through a
     * route that has no publisher yet.
     */
    public function for(SocialChannel $channel): PublisherInterface
    {
        $publisher = $this->publishers[$channel->key()] ?? null;

        if ($publisher === null) {
            throw SocialException::validation('This target\'s channel is one nothing can publish.');
        }

        return $publisher;
    }
}
