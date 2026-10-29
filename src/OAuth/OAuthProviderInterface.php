<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

use Spora\Plugins\Social\Model\SocialProvider;

/**
 * One provider's OAuth flow.
 *
 * A provider is a login, not a network: Meta's login yields an Instagram
 * account and a Facebook Page, LinkedIn's yields a member and the
 * organisations the member administers. The accounts come back from
 * {@see self::resolveAccounts()}, each carrying the network it is on.
 *
 * The providers differ in more than their URLs, so the differences live in
 * the provider rather than in a shared client with switches.
 */
interface OAuthProviderInterface
{
    public function provider(): SocialProvider;

    /**
     * The URL the operator's browser is sent to.
     *
     * The PKCE verifier is passed in rather than generated here because the
     * verifier has to survive the round trip to the platform's callback:
     * it is carried in the signed state.
     */
    public function authorizationUrl(string $state, string $pkceVerifier): string;

    /**
     * Exchange the authorization code for tokens.
     */
    public function exchangeCode(string $code, string $pkceVerifier): OAuthTokenSet;

    /**
     * Renew the tokens ahead of their expiry.
     */
    public function refresh(OAuthTokenSet $tokens): OAuthTokenSet;

    /**
     * The accounts the token can publish as.
     *
     * @return list<OAuthAccountIdentity>
     */
    public function resolveAccounts(OAuthTokenSet $tokens): array;
}
