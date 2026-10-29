<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use Spora\Core\Config;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\OAuth\LinkedInPermissions;
use Spora\Plugins\Social\OAuth\LinkedInProvider;
use Spora\Plugins\Social\OAuth\MetaProvider;
use Spora\Plugins\Social\OAuth\OAuthAccountIdentity;
use Spora\Plugins\Social\OAuth\OAuthProviderInterface;
use Spora\Plugins\Social\OAuth\OAuthTokenSet;
use Spora\Plugins\Social\OAuth\SocialStateCodec;
use Spora\Plugins\Social\Service\ApiVersions;
use Spora\Plugins\Social\Service\SocialAppUrlProbe;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Plugins\Social\Service\SocialConnectionTokens;
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
    public readonly LiveVariableNames $names;

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

    private readonly LiveRequirements $requirements;

    private function __construct()
    {
        $this->secrets = new LiveSecrets();
        $this->names = new LiveVariableNames();
        $this->credentials = new LiveCredentials($this->names);
        $this->report = new LiveReport(LiveReport::defaultPath(), $this->secrets);
        $this->requirements = new LiveRequirements($this);

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
        $provider = $this->names->providerOf($platform);

        return $this->providers[$provider] ??= match ($provider) {
            'linkedin' => new LinkedInProvider(
                (string) $this->credentials->clientId($platform),
                (string) $this->credentials->clientSecret($platform),
                $this->redirectUri($platform),
                $this->linkedInPermissions(),
                httpClient: $this->oauthRecorder($platform)->client(),
                versions: $this->versions(),
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
     * The permissions the operator's LinkedIn app asks for.
     */
    public function linkedInPermissions(): LinkedInPermissions
    {
        return new LinkedInPermissions(
            organizationScope: $this->credentials->linkedInOrganizationScope(),
            readOrganizationAcls: $this->credentials->linkedInReadOrganizationAcls(),
            readOrganizationStats: $this->credentials->linkedInReadOrganizationStats(),
            readMemberFeed: $this->credentials->linkedInReadMemberFeed(),
            readOrganizationFeed: $this->credentials->linkedInReadOrganizationFeed(),
        );
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
            new SocialAppUrlProbe(HttpClient::create()),
            new SocialConnectionTokens($this->settings(), new FakeProviderResolver([]), $this->security()),
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
        $provider = $this->names->providerOf($platform);

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
        return $this->tokens[$this->names->providerOf($platform)] ?? new OAuthTokenSet(accessToken: '');
    }

    /**
     * The accounts a token speaks for, read once per run.
     *
     * @return list<OAuthAccountIdentity>
     */
    public function accounts(SocialPlatform $platform): array
    {
        $provider = $this->names->providerOf($platform);

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
     */
    public function missingRequirement(SocialPlatform $platform, int $needs): ?string
    {
        return $this->requirements->missing($platform, $needs);
    }

    /**
     * Whether a login's token was bought in this run.
     */
    public function hasToken(string $provider): bool
    {
        return isset($this->tokens[$provider]);
    }

    /**
     * Whether a login's token exchange was tried in this run.
     */
    public function hasExchange(string $provider): bool
    {
        return isset($this->exchanges[$provider]);
    }
}
