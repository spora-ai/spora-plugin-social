<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Spora\Plugins\Social\Model\SocialPlatform;

/**
 * The three publishing adapters, resolved by platform.
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
            SocialPlatform::LinkedIn->value  => $linkedIn,
            SocialPlatform::Facebook->value  => $facebook,
            SocialPlatform::Instagram->value => $instagram,
        ];
    }

    public function for(SocialPlatform $platform): PublisherInterface
    {
        return $this->publishers[$platform->value];
    }
}
