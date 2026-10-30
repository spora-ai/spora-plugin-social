<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

use Spora\Plugins\Social\Model\SocialProvider;

/**
 * Resolves the OAuth provider of a login.
 *
 * An interface rather than the concrete factory so the connection service
 * can be exercised against a provider the test controls, and so another
 * provider can be added without touching it.
 */
interface OAuthProviderResolver
{
    public function for(SocialProvider $provider): OAuthProviderInterface;
}
