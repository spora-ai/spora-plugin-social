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
 * the conversion lives here rather than in each provider.
 */
final class TokenExchange
{
    /**
     * @param array<string, mixed> $extra
     */
    public function toTokenSet(AccessTokenInterface $token, array $extra = []): OAuthTokenSet
    {
        $expiresAt = null;
        if ($token->getExpires() !== null) {
            $expiresAt = new DateTimeImmutable('@' . $token->getExpires());
        }

        return new OAuthTokenSet(
            accessToken: $token->getToken(),
            refreshToken: $this->stringOrNull($token->getRefreshToken()),
            expiresAt: $expiresAt,
            scopes: $this->scopesOf($token->getValues()),
            extra: $extra,
        );
    }

    /**
     * The scopes the platform answered with.
     *
     * **The separator of the answer is not the separator of the request.**
     * A client asks for scopes separated the way the platform wants them —
     * LinkedIn with spaces, Facebook with commas — and a platform answers
     * with commas whatever it was asked with. Splitting an answer on the
     * request's separator leaves the whole list as one scope, and every
     * scope check then fails.
     *
     * A scope token is neither a comma nor a space, so both are accepted as
     * separators and the OAuth spec's space-delimited answer reads too.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    public function scopesOf(array $values): array
    {
        $scope = $values['scope'] ?? null;
        if (!is_string($scope) || $scope === '') {
            return [];
        }

        $parts = preg_split('/[,\s]+/', $scope) ?: [];

        return array_values(array_filter($parts));
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
