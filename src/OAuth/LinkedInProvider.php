<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

use GuzzleHttp\ClientInterface;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Service\ApiVersions;

/**
 * LinkedIn: authorization code with PKCE, and OpenID Connect on top.
 *
 * The scopes are the Posts API permissions, and the restricted ones are
 * opt-in one by one: LinkedIn grants `r_organization_admin`,
 * `rw_organization_admin` and `r_member_social_feed` to selected
 * developers only, and an app that asks for a permission it does not hold
 * is refused the whole authorization request — so each is asked for only
 * when the operator says their app has it.
 *
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
 */
final class LinkedInProvider extends AbstractOAuthProvider
{
    public const AUTHORIZE_URL = 'https://www.linkedin.com/oauth/v2/authorization';
    public const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';
    public const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';
    public const ORGANIZATION_ACLS_URL = 'https://api.linkedin.com/rest/organizationAcls';

    public const SCOPE_OPENID = 'openid';
    public const SCOPE_PROFILE = 'profile';
    public const SCOPE_EMAIL = 'email';
    public const SCOPE_MEMBER_SOCIAL = 'w_member_social';
    public const SCOPE_ORGANIZATION_SOCIAL = 'w_organization_social';
    public const SCOPE_ORGANIZATION_ADMIN = 'r_organization_admin';
    public const SCOPE_ORGANIZATION_ADMIN_REPORTING = 'rw_organization_admin';
    public const SCOPE_MEMBER_SOCIAL_FEED = 'r_member_social_feed';
    public const SCOPE_ORGANIZATION_SOCIAL_FEED = 'r_organization_social_feed';

    private readonly bool $organizationScopeRequested;
    private readonly bool $readOrganizationAclsRequested;
    private readonly bool $readOrganizationStatsRequested;
    private readonly bool $readMemberFeedRequested;
    private readonly bool $readOrganizationFeedRequested;
    private readonly ApiVersions $versions;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        bool $includeOrganizationScope = false,
        ?ClientInterface $httpClient = null,
        ?ApiVersions $versions = null,
        bool $readOrganizationAcls = false,
        bool $readOrganizationStats = false,
        bool $readMemberFeed = false,
        bool $readOrganizationFeed = false,
    ) {
        parent::__construct($clientId, $clientSecret, $redirectUri, $httpClient);

        $this->organizationScopeRequested = $includeOrganizationScope;
        $this->readOrganizationAclsRequested = $readOrganizationAcls;
        $this->readOrganizationStatsRequested = $readOrganizationStats;
        $this->readMemberFeedRequested = $readMemberFeed;
        $this->readOrganizationFeedRequested = $readOrganizationFeed;
        $this->versions = $versions ?? new ApiVersions(
            ApiVersions::DEFAULT_GRAPH,
            ApiVersions::DEFAULT_LINKEDIN,
        );
    }

    public function provider(): SocialProvider
    {
        return SocialProvider::LinkedIn;
    }

    protected function authorizeUrl(): string
    {
        return self::AUTHORIZE_URL;
    }

    protected function tokenUrl(): string
    {
        return self::TOKEN_URL;
    }

    protected function resourceOwnerUrl(): string
    {
        return self::USERINFO_URL;
    }

    /**
     * @return list<string>
     */
    protected function getDefaultScopes(): array
    {
        $scopes = [
            self::SCOPE_OPENID,
            self::SCOPE_PROFILE,
            self::SCOPE_EMAIL,
            self::SCOPE_MEMBER_SOCIAL,
        ];

        if ($this->organizationScopeRequested) {
            // Writing as an organisation. The permissions that find the
            // organisations and read their numbers are asked for on their
            // own, so an app without them is not refused the whole
            // authorization request.
            $scopes[] = self::SCOPE_ORGANIZATION_SOCIAL;
        }

        if ($this->readOrganizationAclsRequested) {
            // The permission that finds the organisations the member
            // administers.
            //
            // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-access-control-by-role
            $scopes[] = self::SCOPE_ORGANIZATION_ADMIN;
        }

        if ($this->readOrganizationStatsRequested) {
            // The permission that reads an organisation's share statistics.
            //
            // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/share-statistics
            $scopes[] = self::SCOPE_ORGANIZATION_ADMIN_REPORTING;
        }

        if ($this->readMemberFeedRequested) {
            // The permission that reads a member post's reactions.
            //
            // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
            $scopes[] = self::SCOPE_MEMBER_SOCIAL_FEED;
        }

        if ($this->readOrganizationFeedRequested) {
            // The permission that reads an organisation post's reactions.
            //
            // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
            $scopes[] = self::SCOPE_ORGANIZATION_SOCIAL_FEED;
        }

        return $scopes;
    }

    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * @return list<OAuthAccountIdentity>
     */
    public function resolveAccounts(OAuthTokenSet $tokens): array
    {
        $profile = $this->apiGet(
            self::USERINFO_URL,
            [],
            ['Authorization' => 'Bearer ' . $tokens->accessToken],
        );

        $personId = (string) ($profile['sub'] ?? '');
        if ($personId === '') {
            throw SocialException::platform('LinkedIn did not return a member id.', 'IDENTITY_UNRESOLVED');
        }

        $accounts = [
            new OAuthAccountIdentity(
                platform: SocialPlatform::LinkedIn,
                externalId: 'urn:li:person:' . $personId,
                kind: SocialAccountKind::Member,
                displayName: $this->stringOrNull($profile['name'] ?? null),
                username: $this->stringOrNull($profile['email'] ?? null),
                extra: ['author_urn' => 'urn:li:person:' . $personId],
            ),
        ];

        return [...$accounts, ...$this->resolveOrganizations($tokens)];
    }

    /**
     * The organisation pages the member administers.
     *
     * `GET /rest/organizationAcls?q=roleAssignee` — "Find a Member's
     * Organization Access Control Information".
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-access-control-by-role
     *
     * @return list<OAuthAccountIdentity>
     */
    private function resolveOrganizations(OAuthTokenSet $tokens): array
    {
        if (!in_array(self::SCOPE_ORGANIZATION_ADMIN, $tokens->scopes, true)) {
            return [];
        }

        $acls = $this->apiGet(
            self::ORGANIZATION_ACLS_URL,
            ['q' => 'roleAssignee'],
            $this->versions->linkedInRestHeaders($tokens->accessToken),
        );

        $accounts = [];

        foreach ($this->listOf($acls, 'elements') as $element) {
            // A role that is only REQUESTED is not a role yet: the member
            // cannot post as that organisation until it is approved.
            if (($element['state'] ?? null) !== 'APPROVED') {
                continue;
            }

            $urn = $this->stringOrNull($element['organization'] ?? $element['organizationTarget'] ?? null);
            if ($urn === null) {
                continue;
            }

            $accounts[] = new OAuthAccountIdentity(
                platform: SocialPlatform::LinkedIn,
                externalId: $urn,
                kind: SocialAccountKind::Organization,
                displayName: $this->organizationName($urn, $tokens),
                extra: ['author_urn' => $urn, 'organization_id' => $this->organizationId($urn)],
            );
        }

        return $accounts;
    }

    /**
     * The organisation's name.
     *
     * `GET /rest/organizations/{organization ID}` — "Retrieve an
     * Administered Organization". The documentation names the path both
     * ways on the same page (`/rest/organizations/{id}` and
     * `/rest/organization/{id}`); the plural form is the one its own
     * examples use.
     *
     * `localizedName` is the plain string; `name` is a locale map.
     *
     * The lookup is refused for an organisation the member does not
     * administer, and the account is connected either way — so a refusal
     * costs the name, not the connection.
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-lookup-api
     */
    private function organizationName(string $urn, OAuthTokenSet $tokens): ?string
    {
        try {
            $organization = $this->apiGet(
                'https://api.linkedin.com/rest/organizations/' . $this->organizationId($urn),
                [],
                $this->versions->linkedInRestHeaders($tokens->accessToken),
            );
        } catch (SocialException) {
            return null;
        }

        return $this->stringOrNull($organization['localizedName'] ?? null);
    }

    private function organizationId(string $urn): string
    {
        return str_starts_with($urn, 'urn:li:organization:')
            ? substr($urn, strlen('urn:li:organization:'))
            : $urn;
    }

    /**
     * @param  array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function listOf(array $payload, string $key): array
    {
        $items = $payload[$key] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
