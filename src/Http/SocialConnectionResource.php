<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Http;

use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Service\AccountReporting;
use Spora\Plugins\Social\Service\SocialConnectionService;

/**
 * The wire shape of a connection, with the accounts it covers.
 *
 * The accounts ride along because the connection is what the frontend
 * needs: one login, and the accounts it published as.
 *
 * The expiry and the token's health belong to the connection — the token
 * is the connection's — while what an account can report is the
 * account's own business.
 */
final class SocialConnectionResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SocialConnection $connection): array
    {
        $accounts = $connection->accounts()->orderBy('platform')->orderBy('id')->get();

        $scopes = self::scopesOf($connection);

        return [
            'id'                   => (int) $connection->id,
            'principal_id'         => (int) $connection->principal_id,
            'provider'             => (string) $connection->provider,
            'business_external_id' => $connection->business_external_id,
            'config_id'            => $connection->config_id,
            'expires_at'           => $connection->expires_at?->toIso8601String(),
            'status'               => SocialConnectionService::statusOf($connection),
            'needs_reconnect'      => SocialConnectionService::needsReconnect($connection),
            'scopes'               => $scopes,
            'accounts'             => self::accounts($accounts, $scopes),
            'created_at'           => $connection->created_at?->toIso8601String(),
            'updated_at'           => $connection->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, SocialAccount> $accounts
     * @param list<string>                                             $scopes
     * @return list<array<string, mixed>>
     */
    private static function accounts($accounts, array $scopes): array
    {
        $rows = [];

        foreach ($accounts as $account) {
            $reporting = AccountReporting::of($account, $scopes);

            $rows[] = [
                'id'           => (int) $account->id,
                'account_id'   => (int) $account->id,
                'platform'     => (string) $account->platform,
                'kind'         => (string) $account->kind,
                'external_id'  => $account->external_id,
                'display_name' => $account->display_name,
                'username'     => $account->username,
                'followers'    => $account->followers,
                'status'       => (string) $account->status,
                'connected_at' => $account->connected_at?->toIso8601String(),
                'can_report'   => $reporting['can_report'],
                'report_note'  => $reporting['report_note'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function scopesOf(SocialConnection $connection): array
    {
        if ($connection->scopes === null || $connection->scopes === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(' ', $connection->scopes))));
    }
}
