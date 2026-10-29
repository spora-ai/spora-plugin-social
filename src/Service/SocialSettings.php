<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Core\Config;
use Spora\Core\RequestOrigin;
use Spora\Plugins\Social\Model\SocialProvider;

/**
 * The plugin's operator settings, read through core's resolved config.
 *
 * The keys are the full `SPORA_SOCIAL_*` names: the plugin's settings
 * provider declares them without the prefix and core's settings registry
 * builds the full key, so this class reads the same name the operator
 * writes in `.env` and in the admin UI.
 *
 * A missing key resolves to null rather than an exception — "not
 * configured" is a state the connect flow has to be able to report, not a
 * crash.
 */
final class SocialSettings
{
    public const KEY_LINKEDIN_CLIENT_ID = 'SPORA_SOCIAL_LINKEDIN_CLIENT_ID';
    public const KEY_LINKEDIN_CLIENT_SECRET = 'SPORA_SOCIAL_LINKEDIN_CLIENT_SECRET';
    public const KEY_META_APP_ID = 'SPORA_SOCIAL_META_APP_ID';
    public const KEY_META_APP_SECRET = 'SPORA_SOCIAL_META_APP_SECRET';
    public const KEY_META_CONFIG_ID = 'SPORA_SOCIAL_META_CONFIG_ID';
    public const KEY_FRONTEND_URL = 'SPORA_SOCIAL_FRONTEND_URL';
    public const KEY_LINKEDIN_ORGANIZATION_SCOPE = 'SPORA_SOCIAL_LINKEDIN_ORGANIZATION_SCOPE';
    public const KEY_LINKEDIN_READ_ORG_ACLS = 'SPORA_SOCIAL_LINKEDIN_READ_ORG_ACLS';
    public const KEY_LINKEDIN_READ_ORG_STATS = 'SPORA_SOCIAL_LINKEDIN_READ_ORG_STATS';
    public const KEY_LINKEDIN_READ_MEMBER_FEED = 'SPORA_SOCIAL_LINKEDIN_READ_MEMBER_FEED';
    public const KEY_LINKEDIN_READ_ORG_FEED = 'SPORA_SOCIAL_LINKEDIN_READ_ORG_FEED';
    public const KEY_GRAPH_API_VERSION = 'SPORA_SOCIAL_GRAPH_API_VERSION';
    public const KEY_LINKEDIN_API_VERSION = 'SPORA_SOCIAL_LINKEDIN_API_VERSION';

    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * The app id of the provider's app.
     *
     * Meta's one app serves both networks: the Instagram API with Unified
     * Login is built on Facebook Login for Business, and the login dialog
     * is the Facebook one.
     */
    public function clientId(SocialProvider $provider): ?string
    {
        return $this->nonEmpty($this->config->getString($this->clientIdKey($provider)));
    }

    public function clientSecret(SocialProvider $provider): ?string
    {
        return $this->nonEmpty($this->config->getString($this->clientSecretKey($provider)));
    }

    public function isConfigured(SocialProvider $provider): bool
    {
        return !$this->needsSetup($provider);
    }

    /**
     * Whether the provider still needs something set up.
     *
     * Meta needs the app id, the app secret **and** the configuration id;
     * LinkedIn needs its client id and client secret.
     *
     * This is the one answer: the connect flow refuses on it and the
     * accounts screen shows the notice on it, so the operator is never
     * told one thing and then refused for another.
     */
    public function needsSetup(SocialProvider $provider): bool
    {
        if ($this->clientId($provider) === null || $this->clientSecret($provider) === null) {
            return true;
        }

        return $provider === SocialProvider::Meta && $this->metaConfigId() === null;
    }

    /**
     * The Facebook Login for Business configuration the Meta login uses.
     *
     * The configuration is the list of permissions and assets the app asks
     * its clients for. Its id is not a secret — "it identifies the
     * configuration but doesn't give access to any data" — but the login
     * cannot happen without it.
     */
    public function metaConfigId(): ?string
    {
        return $this->nonEmpty($this->config->getString(self::KEY_META_CONFIG_ID));
    }

    /**
     * The public origin of this Spora install.
     *
     * Instagram fetches media from it, so it has to be reachable from the
     * internet — not only from the operator's network — and the OAuth
     * redirect has to be an address a platform can be given, not a path.
     *
     * The operator's own answer wins: `SPORA_APP_URL` is what they
     * registered with every platform, and the host a request happened to
     * carry is only a guess. The detected origin is the fallback, and
     * behind a proxy it is not even that — which is why the operator has
     * to say it.
     */
    public function appUrl(): ?string
    {
        return self::resolveOrigin(
            $this->nonEmpty($this->config->getString('SPORA_APP_URL')),
            RequestOrigin::detect(),
        );
    }

    /**
     * The public origin, when there is one.
     *
     * `RequestOrigin::detect()` answers `http://` when the request
     * carried no host at all, and that is not an address anyone can
     * register — so it is no address at all. A configured value that is
     * not an origin is refused the same way rather than quietly replaced
     * by the guess.
     */
    public static function resolveOrigin(?string $configured, string $detected): ?string
    {
        $origin = $configured ?? $detected;

        $host = parse_url($origin, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        return rtrim($origin, '/');
    }

    /**
     * Whether the instance has an address a platform can be given.
     *
     * The origin falls back to the request's own host, and on the command
     * line there is no request: the guess is `http://localhost`, which no
     * platform will accept. A setting that named such an address would be
     * telling the operator to register something that cannot work.
     */
    public function hasPublicAddress(): bool
    {
        $origin = $this->appUrl();

        if ($origin === null) {
            return false;
        }

        return parse_url($origin, PHP_URL_HOST) !== 'localhost';
    }

    /**
     * Where the operator's browser is sent after the OAuth round trip.
     *
     * Defaults to the app URL because that is where the app is served. In
     * local development the SPA runs on the Vite server and the API on
     * another origin, so the two differ and the setting is the difference.
     */
    public function frontendUrl(): string
    {
        return $this->frontendUrlOverride() ?? $this->appUrl() ?? '';
    }

    /**
     * The URL the platform redirects back to.
     *
     * Null when the instance has no public address: a relative path is
     * not something a platform can be given, so there is nothing to
     * register and nothing to redirect to.
     */
    public function oauthRedirectUri(SocialProvider $provider): ?string
    {
        $base = $this->appUrl();

        if ($base === null) {
            return null;
        }

        return $base . '/api/v1/plugins/social/oauth/' . $provider->value . '/callback';
    }

    /**
     * The Graph API version every Meta endpoint carries.
     */
    public function graphApiVersion(): string
    {
        $version = $this->nonEmpty($this->config->getString(self::KEY_GRAPH_API_VERSION));

        return $version ?? ApiVersions::DEFAULT_GRAPH;
    }

    /**
     * The LinkedIn API version, in `YYYYMM`.
     */
    public function linkedInApiVersion(): string
    {
        $version = $this->nonEmpty($this->config->getString(self::KEY_LINKEDIN_API_VERSION));

        return $version ?? ApiVersions::DEFAULT_LINKEDIN;
    }

    public function clientIdKey(SocialProvider $provider): string
    {
        return match ($provider) {
            SocialProvider::LinkedIn => self::KEY_LINKEDIN_CLIENT_ID,
            SocialProvider::Meta     => self::KEY_META_APP_ID,
        };
    }

    public function clientSecretKey(SocialProvider $provider): string
    {
        return match ($provider) {
            SocialProvider::LinkedIn => self::KEY_LINKEDIN_CLIENT_SECRET,
            SocialProvider::Meta     => self::KEY_META_APP_SECRET,
        };
    }

    private function frontendUrlOverride(): ?string
    {
        return $this->nonEmpty($this->config->getString(self::KEY_FRONTEND_URL));
    }

    private function nonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
