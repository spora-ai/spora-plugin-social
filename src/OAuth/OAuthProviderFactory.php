<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Service\ApiVersions;
use Spora\Plugins\Social\Service\LinkedInPermissionSettings;
use Spora\Plugins\Social\Service\SocialSettings;

/**
 * Builds the provider for a login.
 *
 * A provider whose app id or secret is missing has none: the connect flow
 * says so instead of sending the operator to a platform that will refuse
 * the app.
 */
final class OAuthProviderFactory implements OAuthProviderResolver
{
    public function __construct(
        private readonly SocialSettings $settings,
        private readonly LinkedInPermissionSettings $linkedIn,
        private readonly ApiVersions $versions,
    ) {}

    public function for(SocialProvider $provider): OAuthProviderInterface
    {
        $clientId = $this->settings->clientId($provider);
        $clientSecret = $this->settings->clientSecret($provider);

        if ($clientId === null || $clientSecret === null) {
            throw SocialException::validation(sprintf(
                '%s is not configured. Set its app id and app secret in the plugin settings first.',
                ucfirst($provider->value),
            ));
        }

        // The redirect URI is only on the login URL, and the connect flow
        // refuses before that when the instance has no public address.
        $redirectUri = $this->settings->oauthRedirectUri($provider) ?? '';

        return match ($provider) {
            SocialProvider::Meta => new MetaProvider(
                clientId: $clientId,
                clientSecret: $clientSecret,
                redirectUri: $redirectUri,
                configId: $this->settings->metaConfigId() ?? '',
                versions: $this->versions,
            ),
            SocialProvider::LinkedIn => new LinkedInProvider(
                clientId: $clientId,
                clientSecret: $clientSecret,
                redirectUri: $redirectUri,
                permissions: $this->linkedIn->requested(),
                versions: $this->versions,
            ),
        };
    }
}
