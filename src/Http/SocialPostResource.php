<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Http;

use Spora\Models\MediaAsset;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostMetric;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Service\MetricSnapshot;
use stdClass;

/**
 * The wire shape of a post, with its targets, attachments and numbers.
 *
 * The post's own `metrics` is the sum of its targets' latest readings.
 * A counter the platform does not report stays null in the sum rather
 * than becoming a zero that reads as "nobody saw it".
 */
final class SocialPostResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SocialPost $post): array
    {
        $targets = $post->targets()->orderBy('id')->get();
        $media = $post->media()->orderBy('position')->orderBy('id')->get();

        $targetRows = [];
        $totals = [];
        $anyCounter = false;

        foreach ($targets as $target) {
            $latest = SocialPostMetric::query()
                ->where('target_id', $target->id)
                ->orderByDesc('metric_date')
                ->first();

            $metrics = $latest === null ? null : self::metricsOf($latest);

            if ($metrics !== null) {
                $anyCounter = true;

                foreach (MetricSnapshot::COUNTERS as $counter) {
                    if ($metrics[$counter] === null) {
                        continue;
                    }

                    $totals[$counter] = (int) ($totals[$counter] ?? 0) + (int) $metrics[$counter];
                }
            }

            $account = $target->account;

            $targetRows[] = [
                'id'           => (int) $target->id,
                'account_id'   => (int) $target->account_id,
                'platform'     => (string) $target->platform,
                'status'       => (string) $target->status,
                'external_id'  => $target->external_id,
                'external_url' => $target->external_url,
                'error'        => $target->error,
                'published_at' => $target->published_at?->toIso8601String(),
                'metrics'      => $metrics,
            ];
        }

        return [
            'id'              => (int) $post->id,
            'principal_id'    => (int) $post->principal_id,
            'title'           => $post->title,
            'body'            => $post->body,
            'platform_bodies' => is_array($post->platform_bodies) ? $post->platform_bodies : new stdClass(),
            'status'          => (string) $post->status,
            'source'          => (string) $post->source,
            'scheduled_for'   => $post->scheduled_for?->toIso8601String(),
            'timezone'        => (string) $post->timezone,
            'published_at'    => $post->published_at?->toIso8601String(),
            'accounts'        => self::accounts($targets),
            'targets'         => $targetRows,
            'media'           => self::media($media),
            'metrics'         => $anyCounter ? self::aggregate($totals) : null,
            'created_at'      => $post->created_at?->toIso8601String(),
            'updated_at'      => $post->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The sum of what the targets reported.
     *
     * A counter no target reported stays null: a LinkedIn member post has
     * no impressions, and a zero there reads as "nobody saw it" rather
     * than "this platform does not count that". A counter that was read
     * and is zero stays zero.
     *
     * @param  array<string, int> $totals the counters at least one target reported
     * @return array<string, int|null>
     */
    private static function aggregate(array $totals): array
    {
        $aggregate = [];

        foreach (MetricSnapshot::COUNTERS as $counter) {
            $aggregate[$counter] = isset($totals[$counter]) ? (int) $totals[$counter] : null;
        }

        return $aggregate;
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function metricsOf(SocialPostMetric $row): array
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
     * @param \Illuminate\Database\Eloquent\Collection<int, SocialPostTarget> $targets
     * @return list<array<string, mixed>>
     */
    private static function accounts($targets): array
    {
        $accounts = [];

        foreach ($targets as $target) {
            $account = $target->account;
            if ($account === null) {
                continue;
            }

            $accounts[] = [
                'id'           => (int) $account->id,
                'platform'     => (string) $account->platform,
                'display_name' => $account->display_name,
            ];
        }

        return $accounts;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, \Spora\Plugins\Social\Model\SocialPostMedia> $media
     * @return list<array<string, mixed>>
     */
    private static function media($media): array
    {
        $rows = [];

        foreach ($media as $attachment) {
            $asset = MediaAsset::query()->find((string) $attachment->media_asset_id);

            $rows[] = [
                'media_asset_id'    => (string) $attachment->media_asset_id,
                'position'          => (int) $attachment->position,
                'derivative_format' => $attachment->derivative_format,
                'mime_type'         => $asset?->mime_type,
                'filename'          => $asset?->filename,
                // The opaque path the host SPA renders as an `<img src>`.
                // Null when the asset row is gone: the post still
                // serialises, and the frontend draws a placeholder.
                'asset_url'         => $asset?->asset_url,
            ];
        }

        return $rows;
    }
}
