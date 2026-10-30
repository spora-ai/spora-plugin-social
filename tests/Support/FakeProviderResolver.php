<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Support;

use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\OAuth\OAuthProviderInterface;
use Spora\Plugins\Social\OAuth\OAuthProviderResolver;

/**
 * Hands out the provider of a login.
 */
final class FakeProviderResolver implements OAuthProviderResolver
{
    /**
     * @param array<string, OAuthProviderInterface> $providers
     */
    public function __construct(private readonly array $providers) {}

    public function for(SocialProvider $provider): OAuthProviderInterface
    {
        $resolved = $this->providers[$provider->value] ?? null;

        if ($resolved === null) {
            throw \Spora\Plugins\Social\Exception\SocialException::validation(
                'No provider for ' . $provider->value . '.',
            );
        }

        return $resolved;
    }
}
