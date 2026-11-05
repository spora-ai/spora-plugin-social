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
 * LinkedIn: authorization code, and OpenID Connect on top.
 *
 * The scopes are the Posts API permissions, and the restricted ones are
 * opt-in one by one: LinkedIn grants `r_organization_admin`,
 * `rw_organization_admin` and `r_member_social_feed` to selected
 * developers only, and an app that asks for a permission it does not hold
 * is refused the whole authorization request — so each is asked for only
 * when the operator says their app has it.
 *
 * The member scopes are narrowable too, and for the same reason: the
 * Community Management API product has to be the only product on an app,
 * so an operator who wants organisation pages holds an app that cannot
 * serve OpenID Connect. Asking for it refuses the request outright, so the
 * member is then named by the access-control list instead.
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

    private readonly ApiVersions $versions;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        private readonly LinkedInPermissions $permissions,
        ?ClientInterface $httpClient = null,
        ?ApiVersions $versions = null,
    ) {
        parent::__construct($clientId, $clientSecret, $redirectUri, $httpClient);

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
        $scopes = [];

        if ($this->permissions->signInScope) {
            // OpenID Connect: the member's identity, name and email. An app
            // that holds only the Community Management API product does not
            // serve it, and asking for it refuses the whole request — so the
            // member is named by the access-control list instead.
            $scopes[] = self::SCOPE_OPENID;
            $scopes[] = self::SCOPE_PROFILE;
            $scopes[] = self::SCOPE_EMAIL;
        }

        if ($this->permissions->memberScope) {
            // Posting as the member. The Community Management API product
            // grants it too, so an app holding only that product can ask.
            $scopes[] = self::SCOPE_MEMBER_SOCIAL;
        }

        if ($this->permissions->organizationScope) {
            // Writing as an organisation. The permissions that find the
            // organisations and read their numbers are asked for on their
            // own, so an app without them is not refused the whole
            // authorization request.
            $scopes[] = self::SCOPE_ORGANIZATION_SOCIAL;
        }

        if ($this->permissions->readOrganizationAcls) {
            // The permission that finds the organisations the member
            // administers.
            //
            // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/organization-access-control-by-role
            $scopes[] = self::SCOPE_ORGANIZATION_ADMIN;
        }

        if ($this->permissions->readOrganizationStats) {
            // The permission that reads an organisation's share statistics.
            //
            // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/share-statistics
            $scopes[] = self::SCOPE_ORGANIZATION_ADMIN_REPORTING;
        }

        if ($this->permissions->readMemberFeed) {
            // The permission that reads a member post's reactions.
            //
            // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
            $scopes[] = self::SCOPE_MEMBER_SOCIAL_FEED;
        }

        if ($this->permissions->readOrganizationFeed) {
            // The permission that reads an organisation post's reactions.
            //
            // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
            $scopes[] = self::SCOPE_ORGANIZATION_SOCIAL_FEED;
        }

        return $scopes;
    }

    /**
     * How the scopes are separated in the **request**.
     *
     * LinkedIn takes them separated by spaces — `scope=openid profile email
     * w_member_social` — where Facebook takes commas. It answers with
     * commas either way, which is why the answer is not split with this:
     * see {@see TokenExchange::scopesOf()}.
     */
    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * @return list<OAuthAccountIdentity>
     */
    public function resolveAccounts(OAuthTokenSet $tokens): array
    {
        // The member is read first, so a token that grants OpenID Connect
        // is asked for it before the access-control list — the order the
        // provider asks in is the order the platform answers in.
        $member = in_array(self::SCOPE_OPENID, $tokens->scopes, true)
            ? $this->memberFromUserinfo($tokens)
            : null;

        $acls = $this->organizationAcls($tokens);

        return [
            $member ?? $this->memberFromAcls($acls),
            ...$this->organizationAccounts($acls, $tokens),
        ];
    }

    /**
     * The member who signed in, by OpenID Connect.
     *
     * The answer carries the id, the name and the email.
     */
    private function memberFromUserinfo(OAuthTokenSet $tokens): OAuthAccountIdentity
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

        return $this->memberIdentity(
            $personId,
            $this->exchange->stringOrNull($profile['name'] ?? null),
            $this->exchange->stringOrNull($profile['email'] ?? null),
        );
    }

    /**
     * The member who signed in, by the access-control list.
     *
     * An app holding only the Community Management API product cannot serve
     * OpenID Connect, and asking for it refuses the whole authorization
     * request — so the member is named by the list instead, which says who
     * the role was given to.
     *
     * @param list<array<string, mixed>> $acls
     */
    private function memberFromAcls(array $acls): OAuthAccountIdentity
    {
        $personId = $this->memberIdFrom($acls);
        if ($personId === '') {
            throw SocialException::platform(
                "This token names no member: ask LinkedIn for the member's identity, or for the organisations the member administers.",
                'IDENTITY_UNRESOLVED',
            );
        }

        return $this->memberIdentity($personId, null, null);
    }

    private function memberIdentity(string $personId, ?string $name, ?string $email): OAuthAccountIdentity
    {
        return new OAuthAccountIdentity(
            platform: SocialPlatform::LinkedIn,
            externalId: 'urn:li:person:' . $personId,
            kind: SocialAccountKind::Member,
            displayName: $name,
            username: $email,
            extra: ['author_urn' => 'urn:li:person:' . $personId],
        );
    }

    /**
     * The member the access-control list is about.
     *
     * A role can be given to a member or to an organisation, and only a
     * member names the one who signed in: an organisation's urn read as a
     * member's id names nobody, and it is stored as the member's id and
     * published as the author.
     *
     * @param list<array<string, mixed>> $acls
     */
    private function memberIdFrom(array $acls): string
    {
        $prefix = 'urn:li:person:';

        foreach ($acls as $element) {
            $assignee = $this->exchange->stringOrNull($element['roleAssignee'] ?? null);
            if ($assignee === null || !str_starts_with($assignee, $prefix)) {
                continue;
            }

            return substr($assignee, strlen($prefix));
        }

        return '';
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
    private function organizationAccounts(array $acls, OAuthTokenSet $tokens): array
    {
        $accounts = [];

        foreach ($acls as $element) {
            // A role that is only REQUESTED is not a role yet: the member
            // cannot post as that organisation until it is approved.
            if (($element['state'] ?? null) !== 'APPROVED') {
                continue;
            }

            $urn = $this->exchange->stringOrNull($element['organization'] ?? $element['organizationTarget'] ?? null);
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
     * The access-control list, when the token may read it.
     *
     * @return list<array<string, mixed>>
     */
    private function organizationAcls(OAuthTokenSet $tokens): array
    {
        if (!in_array(self::SCOPE_ORGANIZATION_ADMIN, $tokens->scopes, true)) {
            return [];
        }

        $acls = $this->apiGet(
            self::ORGANIZATION_ACLS_URL,
            ['q' => 'roleAssignee'],
            $this->versions->linkedInRestHeaders($tokens->accessToken),
        );

        return $this->listOf($acls, 'elements');
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

        return $this->exchange->stringOrNull($organization['localizedName'] ?? null);
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
