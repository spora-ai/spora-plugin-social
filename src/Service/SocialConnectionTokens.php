<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\OAuth\OAuthProviderInterface;
use Spora\Plugins\Social\OAuth\OAuthProviderResolver;
use Spora\Plugins\Social\OAuth\OAuthTokenSet;

/**
 * The token of a connection: how it is stored, how it is renewed and how
 * it is read.
 *
 * The token belongs to the connection, not to an account. Under a Meta
 * system-user token one token covers every Instagram account and every
 * Page the client shared, so a token per account would be the same token
 * stored several times — and a refresh would have to keep them in step.
 *
 * The tokens are stored encrypted with core's security manager — the same
 * mechanism the settings use for secrets — and are decrypted only on the
 * way to a platform.
 *
 * The refresh happens *ahead* of the expiry, not when a publish fails: a
 * publish that discovers the expiry is a post that went out to nowhere.
 * A Meta token has no refresh grant, and its provider says so.
 */
final class SocialConnectionTokens
{
    public function __construct(
        private readonly SocialSettings $settings,
        private readonly OAuthProviderResolver $providers,
        private readonly SecurityManagerInterface $security,
    ) {}

    /**
     * The connection the token belongs to, stored.
     *
     * The connection is found again when there is one: a second login for
     * the same provider replaces the token rather than adding a second
     * connection the operator has to choose between.
     */
    public function store(int $principalId, SocialProvider $provider, OAuthTokenSet $tokens): SocialConnection
    {
        $connection = SocialConnection::query()
            ->where('principal_id', $principalId)
            ->where('provider', $provider->value)
            ->first();

        if ($connection === null) {
            $connection = new SocialConnection();
            $connection->principal_id = $principalId;
            $connection->provider = $provider->value;
        }

        $connection->business_external_id = $this->stringOrNull($tokens->extra['business_external_id'] ?? null);
        $connection->config_id = $provider === SocialProvider::Meta ? $this->settings->metaConfigId() : null;
        $connection->access_token = $this->encrypt($tokens->accessToken);
        $connection->refresh_token = $tokens->refreshToken !== null
            ? $this->encrypt($tokens->refreshToken)
            : $connection->refresh_token;
        $connection->expires_at = $this->carbonOf($tokens->expiresAt);
        $connection->scopes = $tokens->scopes !== [] ? implode(' ', $tokens->scopes) : $connection->scopes;
        $connection->status = SocialConnection::STATUS_ACTIVE;
        $connection->save();

        return $connection;
    }

    /**
     * Renew the connection's token now.
     *
     * A token that does not expire is handed back unchanged — the
     * provider says so, and the operator is told why.
     */
    public function refresh(SocialConnection $connection): SocialConnection
    {
        $provider = $this->providerOf($connection);

        $refreshed = $provider->refresh($this->tokenSetOf($connection));

        if ($refreshed->accessToken !== $this->decrypt($connection->access_token)) {
            $connection->access_token = $this->encrypt($refreshed->accessToken);
            $connection->refresh_token = $refreshed->refreshToken !== null
                ? $this->encrypt($refreshed->refreshToken)
                : $connection->refresh_token;
            $connection->expires_at = $this->carbonOf($refreshed->expiresAt);
            $connection->save();
        }

        return $connection->fresh() ?? $connection;
    }

    /**
     * The token to publish with, renewed first when it is close to expiry.
     */
    public function accessToken(SocialConnection $connection): string
    {
        if ($this->needsRefresh($connection)) {
            $connection = $this->refresh($connection);
        }

        return $this->decrypt($connection->access_token);
    }

    /**
     * The token an account publishes with.
     *
     * The account's connection holds it — one login, one token, many
     * accounts.
     */
    public function tokenFor(SocialAccount $account): string
    {
        $connection = $account->connection;

        if ($connection === null) {
            throw SocialException::conflict('This account has no connection, so it has no token.');
        }

        return $this->accessToken($connection);
    }

    /**
     * The scopes the platform granted the connection's token.
     *
     * Empty when the token carries none — a Meta system-user token reports
     * its permissions through the configuration, not through the token
     * response. The caller decides what an empty list means; here it is
     * only the fact.
     *
     * @return list<string>
     */
    public function grantedScopes(SocialConnection $connection): array
    {
        if ($connection->scopes === null || $connection->scopes === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(' ', $connection->scopes))));
    }

    /**
     * The accounts the connection's token speaks for.
     *
     * @return list<\Spora\Plugins\Social\OAuth\OAuthAccountIdentity>
     */
    public function resolveAccounts(SocialConnection $connection, OAuthTokenSet $tokens): array
    {
        return $this->providerOf($connection)->resolveAccounts($tokens);
    }

    /**
     * What a failed call says about the token.
     *
     * The platform naming the token itself is a different answer from
     * "the call failed": only the first one needs the operator to ask
     * their client to connect again.
     */
    public function statusOfFailure(string $message): string
    {
        $text = strtolower($message);

        return str_contains($text, 'token') && (str_contains($text, 'invalid') || str_contains($text, 'revoked'))
            ? SocialConnection::STATUS_REVOKED
            : SocialConnection::STATUS_ERROR;
    }

    /**
     * Whether the token is close enough to its expiry to be worth renewing
     * before the next call.
     *
     * A token with no expiry never needs renewing.
     */
    private function needsRefresh(SocialConnection $connection): bool
    {
        if ($connection->expires_at === null) {
            return false;
        }

        $threshold = DateTimeParser::nowUtc()->addDays(SocialConnectionService::REFRESH_WINDOW_DAYS);

        return $connection->expires_at <= $threshold;
    }

    /**
     * The provider the connection belongs to.
     */
    public function providerOf(SocialConnection $connection): OAuthProviderInterface
    {
        $provider = SocialProvider::tryFromName((string) $connection->provider);

        if ($provider === null) {
            throw SocialException::conflict('This connection has an unknown provider.');
        }

        return $this->providers->for($provider);
    }

    private function tokenSetOf(SocialConnection $connection): OAuthTokenSet
    {
        return new OAuthTokenSet(
            accessToken: $this->decrypt($connection->access_token),
            refreshToken: $connection->refresh_token !== null ? $this->decrypt($connection->refresh_token) : null,
            expiresAt: $connection->expires_at !== null
                ? new DateTimeImmutable('@' . $connection->expires_at->getTimestamp())
                : null,
            scopes: $this->grantedScopes($connection),
            extra: ['business_external_id' => $connection->business_external_id],
        );
    }

    public function encrypt(string $plaintext): string
    {
        return $this->security->encrypt($plaintext)->toStorageString();
    }

    public function decrypt(string $stored): string
    {
        return $this->security->decrypt(new EncryptedValue($stored));
    }

    /**
     * The stored form of an expiry.
     *
     * The token set carries a `DateTimeImmutable`; the column is an
     * Eloquent datetime, which wants a Carbon.
     */
    public function carbonOf(?DateTimeImmutable $when): ?Carbon
    {
        return $when === null ? null : Carbon::instance($when);
    }

    public function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
