<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Model\SocialPost;

/**
 * The copy a post goes out with, per channel.
 *
 * One body for every channel, and a body of its own for the channels
 * whose copy really differs. The hashtags are part of the text that
 * goes out, so they are counted against the limit as well — and the
 * aggregator's per-channel copy travels as `customContent`, because the
 * aggregator's call carries one body for the whole group.
 */
final class PostBodies
{
    public function __construct(private readonly SocialPost $post) {}

    /**
     * The body every channel gets.
     */
    public function shared(): string
    {
        return PlatformFit::composeText((string) $this->post->body, $this->hashtags());
    }

    /**
     * The body one channel gets.
     */
    public function for(SocialChannel $channel): string
    {
        $bodies = $this->post->platform_bodies;

        if (is_array($bodies)) {
            $specific = $bodies[$channel->key()] ?? null;

            if (is_string($specific) && $specific !== '') {
                return PlatformFit::composeText($specific, $this->hashtags());
            }
        }

        return $this->shared();
    }

    /**
     * Whether a channel's copy differs from the shared one.
     */
    public function hasOwn(SocialChannel $channel): bool
    {
        return $this->for($channel) !== $this->shared();
    }

    /**
     * @return list<string>
     */
    private function hashtags(): array
    {
        return is_array($this->post->hashtags) ? $this->post->hashtags : [];
    }
}
