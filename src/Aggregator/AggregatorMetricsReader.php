<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

use Spora\Plugins\Social\Aggregator\Zernio\ZernioClient;
use Spora\Plugins\Social\Aggregator\Zernio\ZernioResponse;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Service\MetricSnapshot;

/**
 * The numbers of one aggregator post.
 *
 * The target's id is **the aggregator's post id** — that is what the
 * analytics lookup needs — and its url is that network's own post, so
 * the operator can click it.
 *
 * Two answers are outcomes, not errors:
 *
 * - **`402`** — analytics is an add-on on the aggregator's plan. The
 *   target reports no numbers, with a note saying why.
 * - **`202`** — still syncing. The reading is skipped, and the daily job
 *   picks it up tomorrow.
 *
 * **One aggregate per post.** The aggregator breaks a post down per
 * network **and per account** — every entry carries the account id — so
 * the target's own entry is the one that answers for it. When there is
 * no such entry, the target reports the aggregate with a note saying it
 * covers every network in that post. The post's totals stay right — the
 * aggregate is the sum the platform saw — and the per-network split is
 * simply not there.
 */
final class AggregatorMetricsReader
{
    /**
     * The aggregator's numbers are an add-on.
     */
    public const ADDON_NOTE = 'Analytics is an add-on on %s\'s plan, so this post has no numbers. The counts stay empty until the plan carries it.';

    /**
     * The post never went out, so there is nothing to count.
     */
    public const FAILED_NOTE = 'The post failed on every network it reached, so there are no numbers for it.';

    /**
     * The numbers are the whole post's.
     */
    public const AGGREGATE_NOTE = 'These are the numbers for the whole %s post — they cover every network it reached, not this account alone.';

    /**
     * The numbers are not in yet.
     */
    public const PENDING_NOTE = 'The numbers for this platform are not in yet — the aggregator is still syncing them, and the daily reading picks them up.';

    public function __construct(
        private readonly ZernioClient $client,
        private readonly AggregatorService $aggregators,
    ) {}

    /**
     * The numbers of one target.
     *
     * Null when the reading is skipped: the aggregator is still syncing,
     * and there is nothing to write down yet.
     */
    public function read(SocialChannel $channel, SocialAccount $account, string $externalId): ?MetricSnapshot
    {
        $connection = $account->connection;

        if ($connection === null) {
            return null;
        }

        $response = $this->client->analytics(
            $this->aggregators->apiKey($connection),
            $externalId,
        );

        return $this->snapshot($channel, $account, $response);
    }

    /**
     * The answer, as a reading.
     */
    private function snapshot(SocialChannel $channel, SocialAccount $account, ZernioResponse $response): ?MetricSnapshot
    {
        $label = AggregatorProvider::tryFromName($channel->via)?->label() ?? 'the aggregator';

        // Still syncing: the reading is skipped, and the daily job picks
        // it up tomorrow.
        if ($response->status === 202) {
            return null;
        }

        $note = $this->noteFor($response, $label);

        if ($note !== null) {
            return new MetricSnapshot(platformMetrics: ['note' => $note]);
        }

        return $this->readingOf($channel, $account, $response, $label);
    }

    /**
     * The note the answer carries, when it is not a reading.
     *
     * @return string|null
     */
    private function noteFor(ZernioResponse $response, string $label): ?string
    {
        if ($response->status === 402) {
            return sprintf(self::ADDON_NOTE, $label);
        }

        if ($response->status === 424) {
            return self::FAILED_NOTE;
        }

        return $response->isSuccessful()
            ? null
            : ($response->errorMessage() ?? 'The aggregator refused the analytics.');
    }

    /**
     * The numbers, as a reading.
     */
    private function readingOf(
        SocialChannel $channel,
        SocialAccount $account,
        ZernioResponse $response,
        string $label,
    ): MetricSnapshot {
        $body = $response->body;
        $aggregate = is_array($body['analytics'] ?? null) ? $body['analytics'] : [];

        $own = $this->entryFor($body, $channel, $account);
        $numbers = is_array($own['analytics'] ?? null) ? $own['analytics'] : null;

        // The aggregator breaks the post down per account when it can,
        // and the numbers are then that account's own.
        if ($numbers !== null && $this->isSynced($own)) {
            return $this->snapshotOf($numbers, ['analytics' => $numbers]);
        }

        // A platform whose analytics are still syncing would be written
        // as zeros, and zeros read as "nobody saw it" — the one thing
        // the plugin promised not to show. The reading is picked up by
        // the daily job.
        if (!$this->isSynced($own) || !$this->isSynced($body)) {
            return new MetricSnapshot(platformMetrics: ['note' => self::PENDING_NOTE]);
        }

        // One aggregate for the whole post: the note says so, because a
        // number without its scope reads as a number about this account.
        return $this->snapshotOf($aggregate, [
            'analytics' => $aggregate,
            'note'      => sprintf(self::AGGREGATE_NOTE, $label),
        ]);
    }

    /**
     * Whether the answer's numbers are in.
     *
     * An answer that does not say is taken as in: the aggregator has
     * always reported a sync state, and a missing one is no reason to
     * throw the numbers away.
     *
     * @param array<string, mixed> $answer
     */
    private function isSynced(array $answer): bool
    {
        $status = $answer['syncStatus'] ?? 'synced';

        return is_string($status) && $status === 'synced';
    }

    /**
     * The entry of the account the target is on.
     *
     * The aggregator breaks the post down per network **and per
     * account** — every entry carries the account id — so two accounts
     * of one network in one post are told apart by it. The network is
     * the fallback, for an answer that carries no account id.
     *
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function entryFor(array $body, SocialChannel $channel, SocialAccount $account): array
    {
        $entries = is_array($body['platformAnalytics'] ?? null) ? $body['platformAnalytics'] : [];
        $accountId = (string) $account->external_id;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ($accountId !== '' && (string) ($entry['accountId'] ?? '') === $accountId) {
                return $entry;
            }
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ((string) ($entry['platform'] ?? '') === $channel->network) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * The numbers, in the plugin's own counters.
     *
     * @param  array<string, mixed> $numbers
     * @param  array<string, mixed> $platformMetrics
     */
    private function snapshotOf(array $numbers, array $platformMetrics): MetricSnapshot
    {
        return new MetricSnapshot(
            reach: $this->intOrNull($numbers['reach'] ?? null),
            impressions: $this->intOrNull($numbers['impressions'] ?? null),
            likes: $this->intOrNull($numbers['likes'] ?? null),
            comments: $this->intOrNull($numbers['comments'] ?? null),
            shares: $this->intOrNull($numbers['shares'] ?? null),
            saves: $this->intOrNull($numbers['saves'] ?? null),
            clicks: $this->intOrNull($numbers['clicks'] ?? null),
            platformMetrics: $platformMetrics,
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
