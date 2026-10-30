<?php

declare(strict_types=1);

namespace Spora\Plugins\Social;

use Spora\Apps\VueAppInterface;

/**
 * The panel the plugin contributes to the host.
 *
 * `name()` must equal the manifest slug: the host resolves
 * `/plugins/<slug>/<entry()>` and `/apps/<slug>` from the same value.
 */
final class SocialApp implements VueAppInterface
{
    public function name(): string
    {
        return 'social';
    }

    public function displayName(): string
    {
        return 'Social';
    }

    public function description(): string
    {
        return 'Publish to LinkedIn, Facebook and Instagram.';
    }

    public function icon(): string
    {
        return 'globe';
    }

    /**
     * `sky`; an unknown token is coerced to `primary` rather than
     * rejected, so a typo silently repaints the tile.
     */
    public function accent(): string
    {
        return 'sky';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
