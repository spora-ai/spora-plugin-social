<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

use DateTimeImmutable;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Throwable;

/**
 * What a grant turned into.
 *
 * The token, the scopes and the expiry are the same for both platforms, so
 * the conversion lives here rather than in each provider. The scope
 * separator is the provider's own — Meta answers with commas and LinkedIn
 * with spaces — so it is passed in rather than decided here.
 */
final class TokenExchange
{
    /**
     * @param array<string, mixed> $extra
     */
    public function toTokenSet(AccessTokenInterface $token, string $scopeSeparator, array $extra = []): OAuthTokenSet
    {
        $expiresAt = null;
        if ($token->getExpires() !== null) {
            $expiresAt = new DateTimeImmutable('@' . $token->getExpires());
        }

        return new OAuthTokenSet(
            accessToken: $token->getToken(),
            refreshToken: $this->stringOrNull($token->getRefreshToken()),
            expiresAt: $expiresAt,
            scopes: $this->scopesOf($token->getValues(), $scopeSeparator),
            extra: $extra,
        );
    }

    /**
     * The scopes the platform answered with, split the way the platform
     * joins them.
     *
     * Splitting on the wrong separator leaves the whole scope string as a
     * single scope, and every scope check then fails.
     *
     * @param  array<string, mixed> $values
     * @return list<string>
     */
    public function scopesOf(array $values, string $scopeSeparator): array
    {
        $scope = $values['scope'] ?? null;
        if (!is_string($scope) || $scope === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode($scopeSeparator, $scope))));
    }

    public function describeFailure(Throwable $e): string
    {
        if ($e instanceof IdentityProviderException) {
            return $e->getMessage();
        }

        return $e->getMessage() !== '' ? $e->getMessage() : 'The platform did not answer as expected.';
    }

    public function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
