<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

use Spora\Plugins\Social\Aggregator\Zernio\ZernioClient;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Service\SocialConnectionTokens;
use Throwable;

/**
 * The aggregator's connection: one API key, and the accounts it covers.
 *
 * An aggregator is not an OAuth provider. There is no login, no refresh
 * grant and no expiry: the key is what the operator pasted, it is held
 * on the connection row like a token, and it is replaced by pasting a
 * new one.
 *
 * The key is written **here and nowhere else**. The agent's tools have
 * no way to write a credential — a credential belongs where a person can
 * see it, replace it and revoke it.
 */
final class AggregatorService
{
    public function __construct(
        private readonly ZernioClient $client,
        private readonly SocialConnectionTokens $tokens,
    ) {}

    /**
     * The connection this aggregator has for the principal, when it has one.
     */
    public function connection(int $principalId, AggregatorProvider $provider): ?SocialConnection
    {
        $connection = SocialConnection::query()
            ->where('principal_id', $principalId)
            ->where('provider', $provider->value)
            ->first();

        return $connection instanceof SocialConnection ? $connection : null;
    }

    /**
     * Whether the aggregator is set up for the principal.
     */
    public function isConfigured(int $principalId, AggregatorProvider $provider): bool
    {
        return $this->connection($principalId, $provider) !== null;
    }

    /**
     * The provider a connection belongs to, when it is an aggregator's.
     */
    public static function providerOf(SocialConnection $connection): ?AggregatorProvider
    {
        return AggregatorProvider::tryFromName((string) $connection->provider);
    }

    /**
     * The key to publish with.
     */
    public function apiKey(SocialConnection $connection): string
    {
        return $this->tokens->accessToken($connection);
    }

    /**
     * Store the key the operator pasted.
     *
     * The key is checked against a cheap authenticated call first, because
     * a key that does not work is the one thing the operator cannot see
     * for themselves. Only a refusal refuses the save: an outage is not
     * the operator's fault, and refusing the key would leave them with
     * no way to set it up at all.
     */
    public function setKey(int $principalId, AggregatorProvider $provider, string $apiKey): SocialConnection
    {
        $key = trim($apiKey);

        if ($key === '') {
            throw SocialException::validation('The API key is empty.');
        }

        $refusal = $this->refusalOf($key);

        if ($refusal !== null) {
            throw SocialException::validation($refusal);
        }

        $connection = $this->connection($principalId, $provider);

        if ($connection === null) {
            $connection = new SocialConnection();
            $connection->principal_id = $principalId;
            $connection->provider = $provider->value;
        }

        $connection->access_token = $this->tokens->encrypt($key);
        // The key does not expire, so the column says so rather than
        // carrying a date the Accounts screen would show as a stale one.
        $connection->refresh_token = null;
        $connection->expires_at = null;
        $connection->scopes = null;
        $connection->status = SocialConnection::STATUS_ACTIVE;
        $connection->save();

        return $connection;
    }

    /**
     * Forget the key.
     *
     * The accounts stay where they are — in Zernio — and the local rows
     * are marked disconnected rather than deleted: a post's history keeps
     * its target, and a target that names a gone account reads as
     * "this account is no longer in Zernio" rather than as a hole.
     */
    public function forgetKey(int $principalId, AggregatorProvider $provider): void
    {
        $connection = $this->connection($principalId, $provider);

        if ($connection === null) {
            return;
        }

        $this->forget($connection);
    }

    /**
     * Forget one connection's key.
     *
     * The accounts are detached rather than deleted: the column is
     * nullable and the connection's delete would take them with it, and
     * a post's history keeps its target. They are marked disconnected,
     * which is the aggregator's own word for an account the key does not
     * reach.
     */
    public function forget(SocialConnection $connection): void
    {
        SocialAccount::query()
            ->where('principal_id', (int) $connection->principal_id)
            ->where('via', (string) $connection->provider)
            ->update([
                'status'        => SocialAccount::STATUS_DISCONNECTED,
                'connection_id' => null,
            ]);

        $connection->delete();
    }

    /**
     * Whether the key still works.
     *
     * A cheap authenticated call — one account — answers it, and answers
     * it without the operator's accounts being read.
     *
     * @return array{status: string, message: string, checked_at: string}
     */
    public function health(SocialConnection $connection): array
    {
        $checkedAt = gmdate('c');

        try {
            $response = $this->client->accounts($this->apiKey($connection), ['limit' => 1]);
        } catch (Throwable $e) {
            return ['status' => SocialConnection::STATUS_ERROR, 'message' => $e->getMessage(), 'checked_at' => $checkedAt];
        }

        if (!$response->isSuccessful()) {
            $status = $response->status === 401 || $response->status === 403
                ? SocialConnection::STATUS_REVOKED
                : SocialConnection::STATUS_ERROR;

            $connection->status = $status;
            $connection->save();

            return [
                'status'     => $status,
                'message'    => $response->errorMessage() ?? 'Zernio refused the API key.',
                'checked_at' => $checkedAt,
            ];
        }

        $connection->status = SocialConnection::STATUS_ACTIVE;
        $connection->save();

        return [
            'status'     => SocialConnection::STATUS_ACTIVE,
            'message'    => 'Zernio accepted this connection\'s API key.',
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * What Zernio says about the key, when it says something.
     */
    private function refusalOf(string $apiKey): ?string
    {
        try {
            $response = $this->client->accounts($apiKey, ['limit' => 1]);
        } catch (Throwable) {
            // Zernio could not be reached. The key is stored anyway and
            // the health check reports it later.
            return null;
        }

        if ($response->isSuccessful()) {
            return null;
        }

        if ($response->status === 401 || $response->status === 403) {
            return 'Zernio refused that API key: ' . ($response->errorMessage() ?? 'the key was not accepted.');
        }

        return null;
    }
}
