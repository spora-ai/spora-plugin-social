<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use Spora\Core\Config;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\OAuth\LinkedInProvider;
use Spora\Plugins\Social\OAuth\MetaProvider;
use Spora\Plugins\Social\OAuth\OAuthAccountIdentity;
use Spora\Plugins\Social\OAuth\OAuthProviderInterface;
use Spora\Plugins\Social\OAuth\OAuthTokenSet;
use Spora\Plugins\Social\OAuth\SocialStateCodec;
use Spora\Plugins\Social\Service\ApiVersions;
use Spora\Plugins\Social\Service\SocialAppUrlProbe;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Tests\Support\FakeProviderResolver;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The live run's shared state: the credentials, the providers built from
 * them, the tokens those providers bought and what has been published.
 *
 * The providers are the plugin's own classes with the recording HTTP client
 * injected — the seam the plugin already offers. Nothing here reimplements
 * a request: the harness asks the plugin to do the work and then looks at
 * what the plugin did.
 */
final class LiveContext
{
    private static ?self $instance = null;

    public readonly LiveCredentials $credentials;
    public readonly LiveSecrets $secrets;
    public readonly LiveReport $report;

    /** @var array<string, OAuthProviderInterface> */
    private array $providers = [];

    /** @var array<string, OAuthTokenSet> */
    private array $tokens = [];

    /** @var array<string, list<OAuthAccountIdentity>> */
    private array $accounts = [];

    /** @var array<string, LiveHttpRecorder> */
    private array $http = [];

    /** @var array<string, LiveGuzzleRecorder> */
    private array $oauth = [];

    /** @var array<string, string> */
    private array $published = [];

    /** @var array<string, bool> the platforms whose token exchange was tried */
    private array $exchanges = [];

    private ?SecurityManagerInterface $security = null;

    private ?ApiVersions $versions = null;

    private function __construct()
    {
        $this->secrets = new LiveSecrets();
        $this->credentials = new LiveCredentials();
        $this->report = new LiveReport(LiveReport::defaultPath(), $this->secrets);

        foreach (SocialPlatform::cases() as $platform) {
            $this->secrets->remember($this->credentials->clientSecret($platform));
            $this->secrets->remember($this->credentials->authorizationCode($platform));
            $this->secrets->remember($this->credentials->pkceVerifier($platform));
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function check(SocialPlatform $platform, string $name, int $needs = LiveCheck::CREDENTIALS): LiveCheck
    {
        return new LiveCheck($this, $platform->value, $platform, $name, $needs);
    }

    /**
     * The plugin's provider for a platform, with the recording client.
     *
     * Instagram and Facebook share the Meta provider: one login, one
     * token, both networks.
     */
    public function provider(SocialPlatform $platform): OAuthProviderInterface
    {
        $provider = $this->credentials->providerOf($platform);

        return $this->providers[$provider] ??= match ($provider) {
            'linkedin' => new LinkedInProvider(
                (string) $this->credentials->clientId($platform),
                (string) $this->credentials->clientSecret($platform),
                $this->redirectUri($platform),
                includeOrganizationScope: $this->settings()->linkedInOrganizationScopeEnabled(),
                httpClient: $this->oauthRecorder($platform)->client(),
                versions: $this->versions(),
                readOrganizationAcls: $this->settings()->linkedInReadOrganizationAclsEnabled(),
                readOrganizationStats: $this->settings()->linkedInReadOrganizationStatsEnabled(),
                readMemberFeed: $this->settings()->linkedInReadMemberFeedEnabled(),
                readOrganizationFeed: $this->settings()->linkedInReadOrganizationFeedEnabled(),
            ),
            default => new MetaProvider(
                clientId: (string) $this->credentials->clientId($platform),
                clientSecret: (string) $this->credentials->clientSecret($platform),
                redirectUri: $this->redirectUri($platform),
                configId: (string) $this->credentials->metaConfigId(),
                versions: $this->versions(),
                httpClient: $this->oauthRecorder($platform)->client(),
            ),
        };
    }

    /**
     * The redirect uri the platform has to send the browser back to.
     *
     * Built by the plugin's own settings class, so the address the operator
     * registers is the address the plugin will send — not a second guess at
     * it.
     */
    public function redirectUri(SocialPlatform $platform): string
    {
        $provider = $platform === SocialPlatform::LinkedIn ? 'linkedin' : 'meta';

        return $this->settings()->oauthRedirectUri(
            $provider === 'linkedin' ? SocialProvider::LinkedIn : SocialProvider::Meta,
        );
    }

    public function settings(): SocialSettings
    {
        return new SocialSettings(new Config([
            'SPORA_APP_URL' => $this->credentials->appUrl() ?? '',
            SocialSettings::KEY_GRAPH_API_VERSION => $this->credentials->graphApiVersion(),
            SocialSettings::KEY_LINKEDIN_API_VERSION => $this->credentials->linkedInApiVersion(),
            SocialSettings::KEY_LINKEDIN_ORGANIZATION_SCOPE => $this->credentials->linkedInOrganizationScope(),
            SocialSettings::KEY_LINKEDIN_READ_ORG_ACLS => $this->credentials->linkedInReadOrganizationAcls(),
            SocialSettings::KEY_LINKEDIN_READ_ORG_STATS => $this->credentials->linkedInReadOrganizationStats(),
            SocialSettings::KEY_LINKEDIN_READ_MEMBER_FEED => $this->credentials->linkedInReadMemberFeed(),
            SocialSettings::KEY_LINKEDIN_READ_ORG_FEED => $this->credentials->linkedInReadOrganizationFeed(),
            SocialSettings::KEY_META_APP_ID => $this->credentials->clientId(SocialPlatform::Facebook),
            SocialSettings::KEY_META_APP_SECRET => $this->credentials->clientSecret(SocialPlatform::Facebook),
            SocialSettings::KEY_META_CONFIG_ID => $this->credentials->metaConfigId(),
        ]));
    }

    /**
     * The api versions the plugin speaks.
     *
     * The operator's own settings decide, so the live run checks the
     * version the install would use and not the one the code defaults to.
     */
    public function versions(): ApiVersions
    {
        return $this->versions ??= new ApiVersions(
            graph: $this->settings()->graphApiVersion(),
            linkedin: $this->settings()->linkedInApiVersion(),
        );
    }

    /**
     * The security manager the live run encrypts its token rows with.
     *
     * A throwaway key: the rows live in the test database for the length of
     * one test and are thrown away with it.
     */
    public function security(): SecurityManagerInterface
    {
        return $this->security ??= new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * The plugin's own account service, so the insights path reads the
     * token out of the account row the way it does in production.
     */
    public function accountService(): SocialConnectionService
    {
        return new SocialConnectionService(
            $this->settings(),
            new FakeProviderResolver([]),
            new SocialStateCodec($this->security()),
            $this->security(),
            new SocialAppUrlProbe(HttpClient::create()),
        );
    }

    /**
     * The tokens of a login, bought once per run.
     *
     * Instagram and Facebook share them: the Meta token covers both
     * networks.
     */
    public function exchange(SocialPlatform $platform): OAuthTokenSet
    {
        $provider = $this->credentials->providerOf($platform);

        if (isset($this->tokens[$provider])) {
            return $this->tokens[$provider];
        }

        $this->exchanges[$provider] = true;

        $tokens = $this->provider($platform)->exchangeCode(
            (string) $this->credentials->authorizationCode($platform),
            (string) $this->credentials->pkceVerifier($platform),
        );

        $this->secrets->remember($tokens->accessToken);
        $this->secrets->remember($tokens->refreshToken);

        return $this->tokens[$provider] = $tokens;
    }

    public function tokens(SocialPlatform $platform): OAuthTokenSet
    {
        return $this->tokens[$this->credentials->providerOf($platform)] ?? new OAuthTokenSet(accessToken: '');
    }

    /**
     * The accounts a token speaks for, read once per run.
     *
     * @return list<OAuthAccountIdentity>
     */
    public function accounts(SocialPlatform $platform): array
    {
        $provider = $this->credentials->providerOf($platform);

        if (!isset($this->accounts[$provider])) {
            if (!isset($this->tokens[$provider])) {
                return [];
            }

            $this->accounts[$provider] = $this->provider($platform)
                ->resolveAccounts($this->tokens[$provider]);
        }

        // One login covers both networks, so the accounts are picked out
        // by the network the caller is about.
        return array_values(array_filter(
            $this->accounts[$provider],
            static fn(OAuthAccountIdentity $identity): bool => $identity->platform === $platform,
        ));
    }

    /**
     * The recorder of the publish side of one platform.
     */
    public function http(SocialPlatform $platform): LiveHttpRecorder
    {
        return $this->http[$platform->value] ??= new LiveHttpRecorder(
            HttpClient::create(['timeout' => 30]),
            $this->secrets,
        );
    }

    /**
     * The recorder of the OAuth side of one platform.
     */
    public function oauthRecorder(SocialPlatform $platform): LiveGuzzleRecorder
    {
        return $this->oauth[$platform->value] ??= new LiveGuzzleRecorder($this->secrets);
    }

    /**
     * @return list<array{method: string, url: string, status: int, headers: array<string, string>, form: array<string, string>, request: string}>
     */
    public function oauthCalls(SocialPlatform $platform): array
    {
        return $this->oauthRecorder($platform)->calls();
    }

    public function rememberPublished(SocialPlatform $platform, string $id): void
    {
        $this->published[$platform->value] = $id;
    }

    public function published(SocialPlatform $platform): ?string
    {
        return $this->published[$platform->value] ?? null;
    }

    /**
     * Why a check cannot run, or null when it can.
     *
     * The reasons name the environment variable that is missing, so the
     * operator is told what to set rather than that something is absent.
     */
    public function missingRequirement(SocialPlatform $platform, int $needs): ?string
    {
        if (($needs & LiveCheck::CREDENTIALS) !== 0) {
            if ($this->credentials->clientId($platform) === null || $this->credentials->clientSecret($platform) === null) {
                return sprintf(
                    'the %s credentials are not in the environment (%s and %s)',
                    $platform->value,
                    $this->credentials->clientIdName($platform),
                    $this->credentials->clientSecretName($platform),
                );
            }

            if ($this->redirectUri($platform) === '') {
                return sprintf(
                    '%s is not set, so there is no redirect uri to send the browser to',
                    LiveCredentials::ENV_REDIRECT_URI,
                );
            }
        }

        if (($needs & LiveCheck::AUTHORIZATION) !== 0) {
            if ($this->credentials->authorizationCode($platform) === null) {
                return sprintf(
                    'no authorization code for %s (%s is not set — the operator did not complete the sign-in)',
                    $platform->value,
                    $this->credentials->authorizationCodeName($platform),
                );
            }

            if ($this->credentials->pkceVerifier($platform) === null) {
                return sprintf(
                    'no pkce verifier for %s (%s is not set)',
                    $platform->value,
                    $this->credentials->pkceVerifierName($platform),
                );
            }
        }

        $provider = $this->credentials->providerOf($platform);

        if (($needs & LiveCheck::TOKEN) !== 0 && !isset($this->tokens[$provider])) {
            return isset($this->exchanges[$provider])
                ? sprintf('the %s token exchange did not produce a token', $provider)
                : sprintf('the %s token exchange did not run in this session', $provider);
        }

        if (($needs & LiveCheck::PUBLIC_IMAGE) !== 0 && $this->credentials->publicImageUrl() === null) {
            return sprintf(
                '%s is not set, so there is no publicly reachable image to publish',
                LiveCredentials::ENV_PUBLIC_IMAGE_URL,
            );
        }

        if (($needs & LiveCheck::PUBLISHED) !== 0 && !isset($this->published[$platform->value])) {
            return sprintf('nothing was published on %s in this run', $platform->value);
        }

        return null;
    }
}
