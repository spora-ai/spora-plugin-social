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
 * **One aggregate per post.** When the aggregator does not break the
 * numbers down per network, the target reports the aggregate with a note
 * saying it covers every network in that post. The post's totals stay
 * right — the aggregate is the sum the platform saw — and the per-network
 * split is simply not there.
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

        return $this->snapshot($channel, $response);
    }

    /**
     * The answer, as a reading.
     */
    private function snapshot(SocialChannel $channel, ZernioResponse $response): ?MetricSnapshot
    {
        $label = AggregatorProvider::tryFromName($channel->via)?->label() ?? 'the aggregator';

        if ($response->status === 402) {
            return new MetricSnapshot(platformMetrics: [
                'note' => sprintf(self::ADDON_NOTE, $label),
            ]);
        }

        if ($response->status === 202) {
            return null;
        }

        if ($response->status === 424) {
            return new MetricSnapshot(platformMetrics: ['note' => self::FAILED_NOTE]);
        }

        if (!$response->isSuccessful()) {
            return new MetricSnapshot(platformMetrics: [
                'note' => $response->errorMessage() ?? 'The aggregator refused the analytics.',
            ]);
        }

        $body = $response->body;
        $aggregate = is_array($body['analytics'] ?? null) ? $body['analytics'] : [];

        $own = $this->entryFor($body, $channel);
        $numbers = is_array($own['analytics'] ?? null) ? $own['analytics'] : null;

        // The aggregator breaks the post down per network when it can,
        // and the numbers are then that network's own.
        if ($numbers !== null) {
            return $this->snapshotOf($numbers, ['analytics' => $numbers]);
        }

        // One aggregate for the whole post: the note says so, because a
        // number without its scope reads as a number about this account.
        return $this->snapshotOf($aggregate, [
            'analytics' => $aggregate,
            'note'      => sprintf(self::AGGREGATE_NOTE, $label),
        ]);
    }

    /**
     * The entry of the network the target is on.
     *
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function entryFor(array $body, SocialChannel $channel): array
    {
        $entries = is_array($body['platformAnalytics'] ?? null) ? $body['platformAnalytics'] : [];

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
