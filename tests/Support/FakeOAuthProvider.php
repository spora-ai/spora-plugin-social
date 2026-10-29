<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Support;

use DateTimeImmutable;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\OAuth\OAuthAccountIdentity;
use Spora\Plugins\Social\OAuth\OAuthProviderInterface;
use Spora\Plugins\Social\OAuth\OAuthTokenSet;

/**
 * An OAuth provider the test controls.
 *
 * The provider factory is concrete, so the account service is exercised
 * against this instead of a real platform.
 */
final class FakeOAuthProvider implements OAuthProviderInterface
{
    public int $refreshCalls = 0;

    /**
     * @param list<OAuthAccountIdentity> $accounts
     */
    public function __construct(
        public readonly SocialProvider $provider,
        public array $accounts = [],
        public readonly ?OAuthTokenSet $refreshed = null,
        /** The message the platform refuses with, when it refuses. */
        public readonly ?string $refusal = null,
    ) {}

    public function provider(): SocialProvider
    {
        return $this->provider;
    }

    public function authorizationUrl(string $state, string $pkceVerifier): string
    {
        return 'https://platform.test/authorize?state=' . $state;
    }

    public function exchangeCode(string $code, string $pkceVerifier): OAuthTokenSet
    {
        return new OAuthTokenSet(accessToken: 'exchanged-token', expiresAt: new DateTimeImmutable('+60 days'));
    }

    public function refresh(OAuthTokenSet $tokens): OAuthTokenSet
    {
        $this->refreshCalls++;

        return $this->refreshed ?? new OAuthTokenSet(accessToken: 'refreshed-token');
    }

    public function resolveAccounts(OAuthTokenSet $tokens): array
    {
        if ($this->refusal !== null) {
            throw new \Spora\Plugins\Social\Exception\SocialException('platform', $this->refusal);
        }

        return $this->accounts;
    }

    /**
     * @return list<OAuthAccountIdentity>
     */
    public static function oneAccount(SocialPlatform $platform, string $externalId, SocialAccountKind $kind): array
    {
        return [
            new OAuthAccountIdentity(
                platform: $platform,
                externalId: $externalId,
                kind: $kind,
                displayName: 'Fabeat GmbH',
            ),
        ];
    }
}
