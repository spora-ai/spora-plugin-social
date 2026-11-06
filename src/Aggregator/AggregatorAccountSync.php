<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Aggregator\Zernio\ZernioClient;
use Spora\Plugins\Social\Aggregator\Zernio\ZernioResponse;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialConnection;

/**
 * The aggregator's accounts, as the plugin's own rows.
 *
 * Read-only: the operator creates, renames, moves and disconnects in the
 * aggregator's UI, and the plugin only learns what is there. An account
 * the aggregator no longer reports is **marked disconnected, not
 * deleted** — a post's history keeps its target, and a target that names
 * a gone account reads as "this account is no longer in Zernio" rather
 * than as a hole.
 *
 * The sync never creates a connection. The key is the operator's, and a
 * sync that made one would be a plugin that writes credentials.
 */
final class AggregatorAccountSync
{
    /**
     * The keys an account object may carry its own account type under.
     *
     * The aggregator's vocabulary is not the plugin's, and the object
     * carries the type under a different name per network — so the
     * plugin looks in the places it knows and falls back to the
     * network's own table.
     *
     * @var list<string>
     */
    private const TYPE_KEYS = [
        'accountType',
        'account_type',
        'accountSubtype',
        'account_subtype',
        'tiktokAccountType',
    ];

    public function __construct(
        private readonly ZernioClient $client,
        private readonly AggregatorService $aggregators,
    ) {}

    /**
     * The accounts the aggregator reports, and what the sync found.
     */
    public function read(SocialConnection $connection): AggregatorSyncResult
    {
        $key = $this->aggregators->apiKey($connection);

        $response = $this->client->accounts($key, ['category' => 'social']);

        if (!$response->isSuccessful()) {
            throw SocialException::platform(
                $response->errorMessage() ?? 'Zernio refused the accounts list.',
                'AGGREGATOR_REQUEST_FAILED',
            );
        }

        $accounts = [];
        $reported = [];

        foreach ($this->listOf($response->body['accounts'] ?? null) as $entry) {
            $account = $this->accountOf($entry);

            if ($account === null) {
                continue;
            }

            $accounts[] = $account;
            $reported[] = $account->externalId;
        }

        $followers = $this->followerCounts($key, $accounts, $this->analyticsAccess($response));

        return new AggregatorSyncResult(
            accounts: $accounts,
            gone: $this->gone($connection, $reported),
            followers: $followers['counts'],
            analyticsAvailable: $followers['available'],
        );
    }

    /**
     * Whether the plan carries the analytics add-on.
     *
     * The accounts list says so, and it is the authority: the count is
     * the add-on's, and a call per account to learn that the plan has
     * none is a call per account for nothing.
     *
     * Null when the answer does not say: the call decides then.
     */
    private function analyticsAccess(ZernioResponse $response): ?bool
    {
        $access = $response->body['hasAnalyticsAccess'] ?? null;

        return is_bool($access) ? $access : null;
    }

    /**
     * The accounts the aggregator reports, written down.
     */
    public function apply(SocialConnection $connection, AggregatorSyncResult $result): void
    {
        foreach ($result->accounts as $account) {
            $this->store($connection, $account, $result->followers[$account->externalId] ?? null, $result->analyticsAvailable);
        }

        foreach ($result->gone as $externalId) {
            SocialAccount::query()
                ->where('principal_id', (int) $connection->principal_id)
                ->where('via', (string) $connection->provider)
                ->where('external_id', $externalId)
                ->update(['status' => SocialAccount::STATUS_DISCONNECTED]);
        }

        $connection->analytics_available = $result->analyticsAvailable;
        $connection->save();
    }

    /**
     * The follower count of every account the aggregator reports.
     *
     * **The account object carries the count when the plan has the
     * analytics add-on**, and a number already in hand is not worth a
     * call: one call per account is a call per account, and the count is
     * only fetched for the accounts whose object does not carry one.
     *
     * The count needs the add-on, so the call can be refused: **this
     * endpoint answers `403` without it**, and `/v1/analytics` answers
     * `402` for the same condition — the two endpoints differ. Neither
     * is a failure of the sync: the accounts are still accounts, and the
     * count stays empty with the reason on the account.
     *
     * The call is made once per account, and the first refusal ends the
     * round: the add-on is a property of the plan, so every account
     * would answer the same way.
     *
     * @param  list<AggregatorAccount> $accounts
     * @param  bool|null               $analyticsAccess what the accounts list said about the add-on
     * @return array{counts: array<string, int>, available: bool}
     */
    private function followerCounts(string $key, array $accounts, ?bool $analyticsAccess): array
    {
        // The plan carries no add-on: the count is not there to be
        // fetched, and a call per account to learn that is a call per
        // account for nothing.
        if ($analyticsAccess === false) {
            return ['counts' => [], 'available' => false];
        }

        $counts = [];
        $missing = [];

        foreach ($accounts as $account) {
            if ($account->followers !== null) {
                $counts[$account->externalId] = $account->followers;

                continue;
            }

            $missing[] = $account;
        }

        if ($missing === []) {
            // Every account carried its count, and the add-on is what
            // puts it there: the plan has it.
            return ['counts' => $counts, 'available' => true];
        }

        foreach ($missing as $account) {
            $response = $this->client->followerStats($key, ['accountIds' => $account->externalId]);

            if ($response->status === 403 || $response->status === 402) {
                return ['counts' => [], 'available' => false];
            }

            if (!$response->isSuccessful()) {
                continue;
            }

            $count = $this->countOf($response->body, $account->externalId);

            if ($count !== null) {
                $counts[$account->externalId] = $count;
            }
        }

        return ['counts' => $counts, 'available' => true];
    }

    /**
     * The follower count of one account, as the answer carries it.
     *
     * @param array<string, mixed> $body
     */
    private function countOf(array $body, string $externalId): ?int
    {
        foreach ($this->listOf($body['accounts'] ?? null) as $entry) {
            if ((string) ($entry['_id'] ?? '') !== $externalId) {
                continue;
            }

            $count = $entry['currentFollowers'] ?? null;

            return is_numeric($count) ? (int) $count : null;
        }

        return null;
    }

    /**
     * The account the aggregator reports, as the plugin reads it.
     *
     * @param array<string, mixed> $entry
     */
    private function accountOf(array $entry): ?AggregatorAccount
    {
        $externalId = (string) ($entry['_id'] ?? '');
        $network = strtolower(trim((string) ($entry['platform'] ?? '')));

        if ($externalId === '' || $network === '') {
            return null;
        }

        return new AggregatorAccount(
            externalId: $externalId,
            network: $network,
            displayName: $this->stringOrNull($entry['displayName'] ?? null),
            username: $this->stringOrNull($entry['username'] ?? null),
            kind: $this->reportedType($entry),
            followers: $this->intOrNull($entry['followersCount'] ?? null),
        );
    }

    /**
     * The account type the aggregator itself reports, when it reports one.
     *
     * @param array<string, mixed> $entry
     */
    private function reportedType(array $entry): ?string
    {
        foreach (self::TYPE_KEYS as $key) {
            $value = $entry[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The accounts the aggregator no longer reports.
     *
     * @param  list<string> $reported
     * @return list<string>
     */
    private function gone(SocialConnection $connection, array $reported): array
    {
        $gone = [];

        $rows = SocialAccount::query()
            ->where('principal_id', (int) $connection->principal_id)
            ->where('via', (string) $connection->provider)
            ->where('status', SocialAccount::STATUS_ACTIVE)
            ->get();

        foreach ($rows as $row) {
            if (!in_array((string) $row->external_id, $reported, true)) {
                $gone[] = (string) $row->external_id;
            }
        }

        return $gone;
    }

    /**
     * Write one account down.
     */
    private function store(
        SocialConnection $connection,
        AggregatorAccount $account,
        ?int $followers,
        bool $analyticsAvailable,
    ): void {
        $row = SocialAccount::query()
            ->where('principal_id', (int) $connection->principal_id)
            ->where('via', (string) $connection->provider)
            ->where('platform', $account->network)
            ->where('external_id', $account->externalId)
            ->first();

        if ($row === null) {
            $row = new SocialAccount();
            $row->principal_id = (int) $connection->principal_id;
            $row->connection_id = $connection->id;
            $row->via = (string) $connection->provider;
            $row->platform = $account->network;
            $row->external_id = $account->externalId;
        }

        $row->kind = AggregatorKind::of($account->network, $account->kind);
        $row->display_name = $account->displayName;
        $row->username = $account->username;
        // The count is emptied when the plan carries no add-on, and kept
        // when the call merely failed: a number that was read is still
        // the last one that was read.
        if (!$analyticsAvailable) {
            $row->followers = null;
        } elseif ($followers !== null) {
            $row->followers = $followers;
        }
        $row->status = SocialAccount::STATUS_ACTIVE;
        $row->connected_at = Carbon::now();
        $row->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $entries = [];

        foreach ($value as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
