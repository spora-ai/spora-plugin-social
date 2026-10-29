<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use Spora\Plugins\Social\Model\SocialPlatform;

/**
 * The credentials the live run reads out of the environment.
 *
 * The names carry `LIVE`, so it is visible at a glance that a value belongs
 * to the harness and not to the install: the plugin's own settings are
 * `SPORA_SOCIAL_*`, and the live suite must not read those — it runs beside
 * the install, not inside it.
 *
 * The names of the variables are {@see LiveVariableNames}' business; this
 * class reads what they hold.
 */
final class LiveCredentials
{
    /**
     * The public address of the install the redirect URIs are built from.
     *
     * It is the origin, not the callback itself: the three platforms have
     * their own callback path, and the plugin builds them from the app
     * address. A per-platform override is not offered, because an address
     * the plugin itself would not use is an address the operator cannot
     * connect with either.
     */
    public const ENV_REDIRECT_URI = 'SPORA_LIVE_REDIRECT_URI';

    public const ENV_PUBLIC_IMAGE_URL = 'SPORA_LIVE_PUBLIC_IMAGE_URL';

    public const ENV_ORGANIZATION_SCOPE = 'SPORA_LIVE_LINKEDIN_ORGANIZATION_SCOPE';

    public const ENV_READ_ORG_ACLS = 'SPORA_LIVE_LINKEDIN_READ_ORG_ACLS';

    public const ENV_READ_ORG_STATS = 'SPORA_LIVE_LINKEDIN_READ_ORG_STATS';

    public const ENV_READ_MEMBER_FEED = 'SPORA_LIVE_LINKEDIN_READ_MEMBER_FEED';

    public const ENV_READ_ORG_FEED = 'SPORA_LIVE_LINKEDIN_READ_ORG_FEED';

    public const ENV_GRAPH_VERSION = 'SPORA_LIVE_GRAPH_API_VERSION';

    public const ENV_LINKEDIN_VERSION = 'SPORA_LIVE_LINKEDIN_API_VERSION';

    public const ENV_META_CONFIG_ID = 'SPORA_LIVE_META_CONFIG_ID';

    public function __construct(
        private readonly LiveVariableNames $names = new LiveVariableNames(),
    ) {}

    public function clientId(SocialPlatform $platform): ?string
    {
        return $this->value($this->names->clientIdName($platform));
    }

    public function clientSecret(SocialPlatform $platform): ?string
    {
        return $this->value($this->names->clientSecretName($platform));
    }

    public function authorizationCode(SocialPlatform $platform): ?string
    {
        return $this->value($this->names->authorizationCodeName($platform));
    }

    public function pkceVerifier(SocialPlatform $platform): ?string
    {
        return $this->value($this->names->pkceVerifierName($platform));
    }

    public function appUrl(): ?string
    {
        return $this->value(self::ENV_REDIRECT_URI);
    }

    public function publicImageUrl(): ?string
    {
        return $this->value(self::ENV_PUBLIC_IMAGE_URL);
    }

    public function linkedInOrganizationScope(): bool
    {
        return $this->value(self::ENV_ORGANIZATION_SCOPE) !== null;
    }

    public function linkedInReadOrganizationAcls(): bool
    {
        return $this->value(self::ENV_READ_ORG_ACLS) !== null;
    }

    public function linkedInReadOrganizationStats(): bool
    {
        return $this->value(self::ENV_READ_ORG_STATS) !== null;
    }

    public function linkedInReadMemberFeed(): bool
    {
        return $this->value(self::ENV_READ_MEMBER_FEED) !== null;
    }

    public function linkedInReadOrganizationFeed(): bool
    {
        return $this->value(self::ENV_READ_ORG_FEED) !== null;
    }

    public function graphApiVersion(): ?string
    {
        return $this->value(self::ENV_GRAPH_VERSION);
    }

    public function linkedInApiVersion(): ?string
    {
        return $this->value(self::ENV_LINKEDIN_VERSION);
    }

    /**
     * The Facebook Login for Business configuration the Meta login uses.
     */
    public function metaConfigId(): ?string
    {
        return $this->value(self::ENV_META_CONFIG_ID);
    }

    /**
     * The redirect URL of an earlier run, and the variable it came from.
     *
     * The provider's spelling is the one the harness documents, because the
     * credentials are the provider's too. The platform's spelling is still
     * accepted: an operator who guessed the old name is not left stuck, and
     * the output says which one was used.
     *
     * @return array{url: ?string, variable: ?string}
     */
    public function redirectUrl(string $provider): array
    {
        foreach ($this->names->redirectUrlNames($provider) as $name) {
            $value = $this->value($name);

            if ($value !== null) {
                return ['url' => $value, 'variable' => $name];
            }
        }

        return ['url' => null, 'variable' => null];
    }

    private function value(string $name): ?string
    {
        $value = getenv($name);

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
