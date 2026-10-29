<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

use DateTimeImmutable;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Service\ApiVersions;

/**
 * Meta, through Facebook Login for Business.
 *
 * One login covers both networks: the client picks a business portfolio,
 * the Instagram professional account and the Facebook Page, and the app
 * gets one **Business Integration System User Access Token** that belongs
 * to that portfolio and "covers every asset your client shared with your
 * app for the chosen business portfolio".
 *
 * Three things follow from that, and they are the reason this provider
 * looks the way it does:
 *
 * - **There is no scope in the login URL.** The permissions and the assets
 *   are the configuration's: "config_id has replaced scope (which should
 *   not be used)".
 * - **There is no refresh.** There is no refresh grant, so a token that
 *   is gone is reconnected, not renewed. The configuration chooses the
 *   expiration, and the token "defaults to never expire" — but the
 *   expiry the platform sends is the expiry the connection carries.
 * - **`/me` is not the client.** It is the business integration system
 *   user, so the accounts come from the endpoints that name them.
 *
 * @see https://developers.facebook.com/documentation/facebook-login/facebook-login-for-business
 * @see https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-unified-login
 */
final class MetaProvider extends AbstractOAuthProvider
{
    /**
     * The permission the Facebook Pages are listed with.
     *
     * The Business Management API's edge needs `business_management`,
     * which grants the whole business portfolio — far more than publishing
     * does. The plugin does not ask for it: the operator adds it to the
     * configuration when they want their Pages listed.
     */
    public const SCOPE_BUSINESS_MANAGEMENT = 'business_management';

    private readonly ApiVersions $versions;

    private readonly string $configId;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $configId,
        ?ApiVersions $versions = null,
        ?\GuzzleHttp\ClientInterface $httpClient = null,
    ) {
        parent::__construct($clientId, $clientSecret, $redirectUri, $httpClient);

        $this->configId = $configId;
        $this->versions = $versions ?? new ApiVersions(
            ApiVersions::DEFAULT_GRAPH,
            ApiVersions::DEFAULT_LINKEDIN,
        );
    }

    public function provider(): SocialProvider
    {
        return SocialProvider::Meta;
    }

    /**
     * The login dialog of the configuration.
     *
     * `https://www.facebook.com/{version}/dialog/oauth?client_id=…&config_id=…&redirect_uri=…&response_type=code`
     *
     * There is no PKCE challenge here: the code is exchanged server to
     * server with the app secret, so it never reaches a public client, and
     * the documented flow has none.
     */
    public function authorizationUrl(string $state, string $pkceVerifier): string
    {
        $query = [
            'client_id'     => $this->clientId,
            'config_id'     => $this->configId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'state'         => $state,
        ];

        return sprintf(
            'https://www.facebook.com/%s/dialog/oauth?%s',
            $this->versions->graph,
            http_build_query($query),
        );
    }

    /**
     * The code exchanged for the system-user token.
     *
     * `GET https://graph.facebook.com/{version}/oauth/access_token?client_id=…&client_secret=…&code=…`
     *
     * The client business id does not come with it: the token management
     * API needs it, so it is a second call, and the connection stores it.
     */
    public function exchangeCode(string $code, string $pkceVerifier): OAuthTokenSet
    {
        $body = $this->apiGet($this->tokenUrl(), [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
        ]);

        $accessToken = $this->stringOrNull($body['access_token'] ?? null);
        if ($accessToken === null) {
            throw SocialException::platform('Meta did not return an access token.', 'OAUTH_EXCHANGE_FAILED');
        }

        $expiresAt = null;
        if (isset($body['expires_in']) && is_numeric($body['expires_in'])) {
            $expiresAt = new DateTimeImmutable('@' . (time() + (int) $body['expires_in']));
        }

        return new OAuthTokenSet(
            accessToken: $accessToken,
            expiresAt: $expiresAt,
            // The permissions are the configuration's, and a system-user
            // token does not always name them. When the platform does, they
            // are what the connection records; when it does not, the
            // connection's scopes stay unknown rather than empty-because-
            // none-were-granted.
            scopes: $this->scopesOf($body),
            extra: ['business_external_id' => $this->clientBusinessId($accessToken)],
        );
    }

    /**
     * A system-user token has no refresh grant, so there is nothing to
     * renew.
     *
     * "An access token also stops working if your client removes your app
     * from their business portfolio or moves the Instagram account to
     * another business portfolio. When an access token stops working, stop
     * retrying and ask your client to connect again."
     */
    public function refresh(OAuthTokenSet $tokens): OAuthTokenSet
    {
        return $tokens;
    }

    /**
     * The accounts the token covers: the Instagram professional accounts
     * and the Facebook Pages the client shared.
     */
    public function resolveAccounts(OAuthTokenSet $tokens): array
    {
        $accounts = $this->assignedInstagramAccounts($tokens->accessToken);

        foreach ($this->assignedPages($tokens) as $page) {
            $accounts[] = $page;
        }

        return $accounts;
    }

    /**
     * The client business id.
     *
     * `GET /me?fields=client_business_id` — "Store the client business ID
     * with the access token. You need it to refresh or fetch your client's
     * access tokens with the Business Integration System User Access Token
     * Management API."
     */
    private function clientBusinessId(string $accessToken): ?string
    {
        $body = $this->apiGet(
            $this->graphUrl('/me'),
            ['fields' => 'client_business_id'],
            ['Authorization' => 'Bearer ' . $accessToken],
        );

        return $this->stringOrNull($body['client_business_id'] ?? null);
    }

    /**
     * The Instagram professional accounts the client shared.
     *
     * `GET /me/assigned_instagram_accounts?fields=id,username` — needs the
     * `instagram_basic` permission. "A client can share more than one
     * Instagram account, and one access token covers all of them."
     */
    private function assignedInstagramAccounts(string $accessToken): array
    {
        $body = $this->apiGet(
            $this->graphUrl('/me/assigned_instagram_accounts'),
            ['fields' => 'id,username'],
            ['Authorization' => 'Bearer ' . $accessToken],
        );

        $accounts = [];

        foreach ($this->rowsOf($body) as $row) {
            $id = $this->stringOrNull($row['id'] ?? null);
            if ($id === null) {
                continue;
            }

            $accounts[] = new OAuthAccountIdentity(
                platform: SocialPlatform::Instagram,
                externalId: $id,
                kind: SocialAccountKind::Business,
                displayName: $this->stringOrNull($row['name'] ?? null),
                username: $this->stringOrNull($row['username'] ?? null),
                extra: ['ig_user_id' => $id],
            );
        }

        return $accounts;
    }

    /**
     * The Facebook Pages the client shared.
     *
     * `GET /{client business id}/owned_pages` — the Business Management
     * API's edge for the pages of a business. It is the one documented way
     * to list the pages a business-scoped token can act for, and it needs
     * the `business_management` permission: "To access accounts using a
     * business_id or for a user who owns any business Pages, the app must
     * be approved for the business_management permission."
     *
     * Without that permission the token cannot list the pages, and the
     * connection covers the Instagram account alone. The pages are not
     * guessed at and no endpoint is invented: if Meta moves the answer,
     * this method is the one that changes.
     *
     * @see https://developers.facebook.com/docs/graph-api/reference/business/owned_pages
     */
    private function assignedPages(OAuthTokenSet $tokens): array
    {
        $clientBusinessId = $this->stringOrNull($tokens->extra['business_external_id'] ?? null);
        if ($clientBusinessId === null) {
            return [];
        }

        try {
            $body = $this->apiGet(
                $this->graphUrl('/' . $clientBusinessId . '/owned_pages'),
                ['fields' => 'id,name,username'],
                ['Authorization' => 'Bearer ' . $tokens->accessToken],
            );
        } catch (SocialException) {
            // The token cannot list the pages — it does not carry
            // `business_management`. The connection covers the Instagram
            // account alone, and the operator sees that there is no Page.
            return [];
        }

        $pages = [];

        foreach ($this->rowsOf($body) as $row) {
            $id = $this->stringOrNull($row['id'] ?? null);
            if ($id === null) {
                continue;
            }

            $pages[] = new OAuthAccountIdentity(
                platform: SocialPlatform::Facebook,
                externalId: $id,
                kind: SocialAccountKind::Page,
                displayName: $this->stringOrNull($row['name'] ?? null),
                username: $this->stringOrNull($row['username'] ?? null),
            );
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $body): array
    {
        $rows = $body['data'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/' . $this->versions->graph . $path;
    }

    protected function authorizeUrl(): string
    {
        return 'https://www.facebook.com/' . $this->versions->graph . '/dialog/oauth';
    }

    protected function tokenUrl(): string
    {
        return $this->graphUrl('/oauth/access_token');
    }

    protected function resourceOwnerUrl(): string
    {
        return $this->graphUrl('/me');
    }

    protected function getScopeSeparator(): string
    {
        return ',';
    }

    /**
     * The permissions the login asks for.
     *
     * None of them here: the permissions are the configuration's, and the
     * login URL carries the configuration id where a scope list would go —
     * "config_id has replaced scope (which should not be used)". The
     * configuration is what the operator sets up in the App Dashboard.
     *
     * @return list<string>
     */
    protected function getDefaultScopes(): array
    {
        return [];
    }
}
