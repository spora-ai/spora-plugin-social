<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\OAuth\OAuthAccountIdentity;
use Spora\Plugins\Social\OAuth\OAuthProviderResolver;
use Spora\Plugins\Social\OAuth\OAuthTokenSet;
use Spora\Plugins\Social\OAuth\SocialStateCodec;
use Throwable;

/**
 * The connections: one login per provider, and the accounts it covers.
 *
 * The token belongs to the connection, not to an account — that is
 * {@see SocialConnectionTokens}' business.
 */
final class SocialConnectionService
{
    /**
     * How long an authorization request stays valid. The operator has to
     * log in to a third-party site and come back; ten minutes is enough
     * and short enough that a leaked state is not useful for long.
     */
    public const STATE_TTL_SECONDS = 600;

    /**
     * A token inside this window is renewed before it is used. The plan
     * calls it ten days, and the connection's own health flags the same
     * window so the operator can see a token that is about to move.
     */
    public const REFRESH_WINDOW_DAYS = 10;

    public function __construct(
        private readonly SocialSettings $settings,
        private readonly OAuthProviderResolver $providers,
        private readonly SocialStateCodec $stateCodec,
        private readonly SocialAppUrlProbe $appUrlProbe,
        private readonly SocialConnectionTokens $tokens,
    ) {}

    /**
     * @return list<SocialConnection>
     */
    public function listForPrincipal(int $principalId): array
    {
        return SocialConnection::query()
            ->where('principal_id', $principalId)
            ->orderBy('provider')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function find(int $connectionId, int $principalId): SocialConnection
    {
        $connection = SocialConnection::query()
            ->where('principal_id', $principalId)
            ->where('id', $connectionId)
            ->first();

        if ($connection === null) {
            throw SocialException::notFound('Connection ' . $connectionId . ' was not found for this principal.');
        }

        return $connection;
    }

    /**
     * Start the OAuth round trip.
     *
     * @return array{authorize_url: string, state: string, expires_at: string}
     */
    public function startConnect(int $principalId, SocialProvider $provider): array
    {
        // One check for everything the provider still needs, so the
        // refusal and the notice on the accounts screen cannot disagree.
        if ($this->settings->needsSetup($provider)) {
            throw SocialException::validation($this->setupMessage($provider));
        }

        // A relative path is not something a platform can be given, so
        // there is nothing to register and nothing to come back to.
        if ($this->settings->oauthRedirectUri($provider) === null) {
            throw SocialException::validation(
                'The instance has no public address, so there is no redirect URI to register. Set SPORA_APP_URL.',
            );
        }

        // Checked here rather than at publish time: Instagram fetches the
        // published media from the internet, and an address only the
        // operator's network can see fails at the last step, with an error
        // that never mentions the URL.
        if (!$this->appUrlProbe->isPubliclyReachable($this->settings->appUrl())) {
            throw SocialException::validation(
                'SPORA_APP_URL must be a publicly reachable HTTPS address before an account can be connected — '
                . 'the platform fetches published media from the internet.',
            );
        }

        $pkce = $this->pkceVerifier();
        $expiresAt = time() + self::STATE_TTL_SECONDS;

        $state = $this->stateCodec->encode([
            'principal_id' => $principalId,
            'provider'     => $provider->value,
            'nonce'        => bin2hex(random_bytes(16)),
            'pkce'         => $pkce,
            'expires_at'   => $expiresAt,
        ]);

        return [
            'authorize_url' => $this->providers->for($provider)->authorizationUrl($state, $pkce),
            'state'         => $state,
            'expires_at'    => gmdate('c', $expiresAt),
        ];
    }

    /**
     * Why the provider cannot be connected yet.
     *
     * The refusal is an instruction, not a diagnosis: the operator is told
     * what to do and where, because "not configured" sends them looking for
     * a flag rather than for the settings.
     */
    private function setupMessage(SocialProvider $provider): string
    {
        return match ($provider) {
            SocialProvider::Meta => 'Meta integration needs setup — add the app id, the app secret and the configuration id in Settings → Plugins.',
            SocialProvider::LinkedIn => 'LinkedIn integration needs setup — add the client id, the client secret and the requested permissions in Settings → Plugins.',
        };
    }

    /**
     * Finish the OAuth round trip: the signed state is the authentication,
     * because the platform's browser arrives with no session.
     *
     * @return array{principal_id: int, connection_id: int, accounts: list<SocialAccount>}
     */
    public function completeConnect(string $code, string $state): array
    {
        $payload = $this->stateCodec->decode($state);

        $principalId = (int) ($payload['principal_id'] ?? 0);
        $provider = SocialProvider::tryFromName((string) ($payload['provider'] ?? ''));
        $pkce = (string) ($payload['pkce'] ?? '');
        $expiresAt = (int) ($payload['expires_at'] ?? 0);

        if ($principalId <= 0 || $provider === null || $pkce === '') {
            throw SocialException::forbidden('The OAuth state does not carry a usable principal and provider.');
        }

        if ($expiresAt > 0 && $expiresAt < time()) {
            throw SocialException::forbidden('The connect request expired. Start it again.');
        }

        $oauth = $this->providers->for($provider);
        $tokens = $oauth->exchangeCode($code, $pkce);

        $connection = $this->tokens->store($principalId, $provider, $tokens);

        $accounts = [];

        foreach ($oauth->resolveAccounts($tokens) as $identity) {
            $accounts[] = $this->storeAccount($connection, $identity);
        }

        return [
            'principal_id'  => $principalId,
            'connection_id' => (int) $connection->id,
            'accounts'      => $accounts,
        ];
    }

    public function disconnect(int $connectionId, int $principalId): void
    {
        $this->find($connectionId, $principalId)->delete();
    }

    /**
     * Renew the connection's token now.
     */
    public function refresh(int $connectionId, int $principalId): SocialConnection
    {
        return $this->tokens->refresh($this->find($connectionId, $principalId));
    }

    /**
     * Ask the platform whether the connection still works.
     *
     * The answer is remembered on the connection: "the token is invalid"
     * and "the call failed" are different answers, and only the first one
     * needs the operator to ask their client to connect again.
     *
     * @return array{status: string, message: string, checked_at: string}
     */
    public function health(int $connectionId, int $principalId): array
    {
        $connection = $this->find($connectionId, $principalId);
        $checkedAt = gmdate('c');

        try {
            $token = $this->tokens->accessToken($connection);
        } catch (SocialException $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'checked_at' => $checkedAt];
        }

        try {
            $this->tokens->resolveAccounts($connection, new OAuthTokenSet(accessToken: $token));
        } catch (Throwable $e) {
            $status = $this->tokens->statusOfFailure($e->getMessage());

            $connection->status = $status;
            $connection->save();

            return ['status' => $status, 'message' => $e->getMessage(), 'checked_at' => $checkedAt];
        }

        $connection->status = SocialConnection::STATUS_ACTIVE;
        $connection->save();

        return [
            'status'     => SocialConnection::STATUS_ACTIVE,
            'message'    => 'The platform accepted this connection\'s token.',
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * The status the connection reads with.
     *
     * The expiry wins over what the last check said: a token whose time
     * has run out is expired, whatever the platform said about it before.
     */
    public static function statusOf(SocialConnection $connection): string
    {
        if ($connection->expires_at !== null && $connection->expires_at->isPast()) {
            return SocialConnection::STATUS_EXPIRED;
        }

        return $connection->status !== '' ? $connection->status : SocialConnection::STATUS_ACTIVE;
    }

    /**
     * Whether the token has to be renewed before a publish will work.
     *
     * A token inside the refresh window is renewed on the next call; one
     * the platform has revoked needs the client to connect again; and a
     * token the platform sent no expiry for never needs either.
     */
    public static function needsReconnect(SocialConnection $connection): bool
    {
        $status = self::statusOf($connection);

        if ($status === SocialConnection::STATUS_EXPIRED || $status === SocialConnection::STATUS_REVOKED) {
            return true;
        }

        if ($connection->expires_at === null) {
            return false;
        }

        return $connection->expires_at <= DateTimeParser::nowUtc()->addDays(self::REFRESH_WINDOW_DAYS);
    }

    /**
     * The token to publish with, renewed first when it is close to expiry.
     */
    public function accessToken(SocialConnection $connection): string
    {
        return $this->tokens->accessToken($connection);
    }

    /**
     * The token an account publishes with.
     */
    public function tokenFor(SocialAccount $account): string
    {
        return $this->tokens->tokenFor($account);
    }

    /**
     * The scopes the platform granted the connection's token.
     *
     * @return list<string>
     */
    public function grantedScopes(SocialConnection $connection): array
    {
        return $this->tokens->grantedScopes($connection);
    }

    public function findAccount(int $accountId, int $principalId): SocialAccount
    {
        $account = SocialAccount::query()
            ->forPrincipal($principalId)
            ->where('id', $accountId)
            ->first();

        if ($account === null) {
            throw SocialException::notFound('Account ' . $accountId . ' was not found for this principal.');
        }

        return $account;
    }

    private function storeAccount(SocialConnection $connection, OAuthAccountIdentity $identity): SocialAccount
    {
        $account = SocialAccount::query()
            ->where('connection_id', $connection->id)
            ->where('platform', $identity->platform->value)
            ->where('external_id', $identity->externalId)
            ->first();

        if ($account === null) {
            $account = new SocialAccount();
            $account->principal_id = $connection->principal_id;
            $account->connection_id = $connection->id;
            $account->platform = $identity->platform->value;
            $account->external_id = $identity->externalId;
        }

        $account->kind = $identity->kind->value;
        $account->display_name = $identity->displayName;
        $account->username = $identity->username;
        $account->followers = $identity->followers;
        $account->status = SocialAccount::STATUS_ACTIVE;
        $account->connected_at = Carbon::now();
        $account->save();

        return $account;
    }

    /**
     * A PKCE verifier: 43 to 128 characters from the unreserved set.
     */
    private function pkceVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }
}
