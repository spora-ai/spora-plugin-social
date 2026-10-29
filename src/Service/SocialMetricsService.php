<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostMetric;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Publish\PlatformHttpClient;

/**
 * The readings the plugin keeps, and the summary the analytics view
 * renders.
 *
 * The reading itself is {@see PlatformMetricsReader}'s business; this class
 * stores what it read and puts it together.
 *
 * Every reading is a dated snapshot. Impressions keep climbing for days
 * after a post goes out and the platforms restate them, so nothing here is
 * final.
 */
final class SocialMetricsService
{
    public function __construct(
        PlatformHttpClient $http,
        SocialConnectionService $connections,
        ApiVersions $versions,
    ) {
        $this->reader = new PlatformMetricsReader($http, $connections, $versions);
    }

    private readonly PlatformMetricsReader $reader;

    /**
     * Read one target's numbers and store the day's snapshot.
     */
    public function collect(SocialPostTarget $target): ?SocialPostMetric
    {
        $post = $target->post;
        $account = $target->account;

        if ($post === null || $account === null) {
            return null;
        }

        $platform = SocialPlatform::tryFromName((string) $target->platform);
        if ($platform === null || $target->external_id === null || $target->external_id === '') {
            return null;
        }

        $snapshot = $this->reader->read($platform, $account, (string) $target->external_id);

        $date = DateTimeParser::nowUtc()->format('Y-m-d');

        $row = SocialPostMetric::query()
            ->where('target_id', $target->id)
            ->where('metric_date', $date)
            ->first();

        if ($row === null) {
            $row = new SocialPostMetric();
            $row->post_id = $target->post_id;
            $row->target_id = $target->id;
            $row->metric_date = $date;
        }

        $row->reach = $snapshot->reach;
        $row->impressions = $snapshot->impressions;
        $row->likes = $snapshot->likes;
        $row->comments = $snapshot->comments;
        $row->shares = $snapshot->shares;
        $row->saves = $snapshot->saves;
        $row->clicks = $snapshot->clicks;
        $row->platform_metrics = $snapshot->platformMetrics;
        $row->measured_at = Carbon::now();
        $row->save();

        return $row;
    }

    /**
     * The summary the analytics view renders.
     *
     * @return array{totals: array<string, int|null>, by_network: list<array<string, mixed>>, series: list<array<string, mixed>>, top_posts: list<array<string, mixed>>, notes: list<string>}
     */
    public function summary(int $principalId, int $days = 30): array
    {
        $since = DateTimeParser::nowUtc()->subDays($days)->format('Y-m-d');

        $rows = $this->metricRows($principalId, $since);

        $totals = [];
        $byNetwork = [];
        $series = [];

        foreach ($rows as $row) {
            $platform = (string) $row->platform;

            $network = $byNetwork[$platform] ?? [
                'platform'    => $platform,
                'reach'       => null,
                'impressions' => null,
                'likes'       => null,
                'comments'    => null,
                'shares'      => null,
                'saves'       => null,
                'clicks'      => null,
                'posts'       => 0,
            ];

            foreach (MetricSnapshot::COUNTERS as $counter) {
                $value = $row->{$counter};
                if ($value === null) {
                    continue;
                }

                $network[$counter] = (int) ($network[$counter] ?? 0) + (int) $value;
                $totals[$counter] = (int) ($totals[$counter] ?? 0) + (int) $value;
            }

            $network['posts'] = (int) $network['posts'] + 1;
            $byNetwork[$platform] = $network;

            $date = (string) $row->metric_date;
            $series[$date] ??= ['date' => $date];
            if ($row->reach !== null) {
                $series[$date][$platform] = (int) ($series[$date][$platform] ?? 0) + (int) $row->reach;
            }
        }

        ksort($series);

        return [
            'totals'     => $this->aggregate($totals),
            'by_network' => array_values($byNetwork),
            'series'     => array_values($series),
            'top_posts'  => $this->topPosts($principalId, $since),
            'notes'      => $this->notes($principalId),
        ];
    }

    /**
     * The sum of what was reported.
     *
     * A counter nobody reported stays null: LinkedIn reports no
     * impressions for a member post, and a zero there reads as "nobody saw
     * it" rather than "this platform does not count that". A counter that
     * was read and is zero stays zero.
     *
     * @param  array<string, int> $totals the counters at least one row reported
     * @return array<string, int|null>
     */
    private function aggregate(array $totals): array
    {
        $aggregate = [];

        foreach (MetricSnapshot::COUNTERS as $counter) {
            $aggregate[$counter] = isset($totals[$counter]) ? (int) $totals[$counter] : null;
        }

        return $aggregate;
    }

    /**
     * One post's numbers, per target.
     *
     * @return array<string, mixed>
     */
    public function postAnalytics(int $postId, int $principalId): array
    {
        $post = SocialPost::query()
            ->forPrincipal($principalId)
            ->where('id', $postId)
            ->first();

        if ($post === null) {
            throw SocialException::notFound('Post ' . $postId . ' was not found for this principal.');
        }

        $targets = [];

        foreach ($post->targets()->get() as $target) {
            $metrics = SocialPostMetric::query()
                ->where('target_id', $target->id)
                ->orderByDesc('metric_date')
                ->first();

            $targets[] = [
                'target_id'    => (int) $target->id,
                'account_id'   => (int) $target->account_id,
                'platform'     => (string) $target->platform,
                'status'       => (string) $target->status,
                'external_id'  => $target->external_id,
                'metrics'      => $metrics === null ? null : $this->metricsOf($metrics),
                'report_note'  => $this->reportNoteFor($target),
            ];
        }

        return [
            'post_id' => (int) $post->id,
            'title'   => $post->title,
            'status'  => (string) $post->status,
            'targets' => $targets,
            'notes'   => $this->notes($principalId),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function metricsOf(SocialPostMetric $row): array
    {
        return [
            'reach'       => $row->reach,
            'impressions' => $row->impressions,
            'likes'       => $row->likes,
            'comments'    => $row->comments,
            'shares'      => $row->shares,
            'saves'       => $row->saves,
            'clicks'      => $row->clicks,
            'measured_at' => $row->measured_at?->toIso8601String(),
        ];
    }

    /**
     * The metric rows of a period.
     *
     * The query builder rather than Eloquent: the rows carry columns from
     * three tables, and a model that does not own them would have to
     * pretend otherwise.
     *
     * @return list<object>
     */
    private function metricRows(int $principalId, string $since): array
    {
        return Capsule::table('social_post_metrics')
            ->join('social_post_targets', 'social_post_targets.id', '=', 'social_post_metrics.target_id')
            ->join('social_posts', 'social_posts.id', '=', 'social_post_metrics.post_id')
            ->where('social_posts.principal_id', $principalId)
            ->where('social_post_metrics.metric_date', '>=', $since)
            ->select([
                'social_post_metrics.metric_date',
                'social_post_metrics.reach',
                'social_post_metrics.impressions',
                'social_post_metrics.likes',
                'social_post_metrics.comments',
                'social_post_metrics.shares',
                'social_post_metrics.saves',
                'social_post_metrics.clicks',
                'social_post_targets.platform',
            ])
            ->get()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topPosts(int $principalId, string $since): array
    {
        $rows = Capsule::table('social_post_metrics')
            ->join('social_post_targets', 'social_post_targets.id', '=', 'social_post_metrics.target_id')
            ->join('social_posts', 'social_posts.id', '=', 'social_post_metrics.post_id')
            ->where('social_posts.principal_id', $principalId)
            ->where('social_post_metrics.metric_date', '>=', $since)
            ->select([
                'social_posts.id as post_id',
                'social_posts.title as title',
                'social_post_targets.platform as platform',
                'social_post_metrics.reach as reach',
                'social_post_metrics.impressions as impressions',
                'social_post_metrics.likes as likes',
                'social_posts.published_at as published_at',
            ])
            ->get();

        $posts = [];
        foreach ($rows as $row) {
            $posts[] = [
                'post_id'      => (int) $row->post_id,
                'title'        => $row->title,
                'platform'     => (string) $row->platform,
                'reach'        => $row->reach === null ? null : (int) $row->reach,
                'impressions'  => $row->impressions === null ? null : (int) $row->impressions,
                'likes'        => $row->likes === null ? null : (int) $row->likes,
                'published_at' => $row->published_at,
            ];
        }

        usort($posts, static function (array $a, array $b): int {
            return ($b['reach'] ?? 0) <=> ($a['reach'] ?? 0);
        });

        return array_slice($posts, 0, 10);
    }

    /**
     * The caveats that belong with the numbers.
     *
     * @return list<string>
     */
    private function notes(int $principalId): array
    {
        $notes = [];

        $memberAccounts = SocialPostTarget::query()
            ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.post_id')
            ->where('social_posts.principal_id', $principalId)
            ->where('social_post_targets.platform', SocialPlatform::LinkedIn->value)
            ->where('social_post_targets.status', SocialPostTarget::STATUS_PUBLISHED)
            ->get();

        foreach ($memberAccounts as $target) {
            $account = $target->account;
            if ($account !== null && $account->kind === SocialAccountKind::Member->value) {
                $notes[] = 'LinkedIn member posts report reactions only — the reach column is empty by design.';
                break;
            }
        }

        $personalInstagram = SocialPostTarget::query()
            ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.post_id')
            ->where('social_posts.principal_id', $principalId)
            ->where('social_post_targets.platform', SocialPlatform::Instagram->value)
            ->get();

        foreach ($personalInstagram as $target) {
            $account = $target->account;
            if ($account !== null && $account->kind === SocialAccountKind::Member->value) {
                $notes[] = 'Instagram personal accounts have no media insights.';
                break;
            }
        }

        $notes[] = 'Every reading is a dated snapshot — the platforms keep revising impressions for days.';

        return $notes;
    }

    private function reportNoteFor(SocialPostTarget $target): ?string
    {
        $account = $target->account;
        if ($account === null) {
            return null;
        }

        $notes = [
            SocialPlatform::LinkedIn->value => AccountReporting::MEMBER_NOTE,
            SocialPlatform::Instagram->value => AccountReporting::PERSONAL_INSTAGRAM_NOTE,
        ];

        if ($account->kind !== SocialAccountKind::Member->value) {
            return null;
        }

        return $notes[$target->platform] ?? null;
    }
}
