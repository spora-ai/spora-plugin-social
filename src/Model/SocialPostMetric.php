<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One target's numbers on one day.
 *
 * A row per day rather than an overwrite, so the time series is a query and
 * not a recomputation. Every reading is a dated snapshot — impressions keep
 * climbing for days and the platforms restate them, so nothing here is
 * final.
 *
 * @property int $id
 * @property int $post_id
 * @property int $target_id
 * @property string $metric_date
 * @property int|null $reach
 * @property int|null $impressions
 * @property int|null $likes
 * @property int|null $comments
 * @property int|null $shares
 * @property int|null $saves
 * @property int|null $clicks
 * @property array<string, mixed>|null $platform_metrics
 * @property \Carbon\Carbon|null $measured_at
 * @property ?\Carbon\Carbon $created_at
 * @property ?\Carbon\Carbon $updated_at
 */
final class SocialPostMetric extends Model
{
    protected $table = 'social_post_metrics';

    /** @var list<string> */
    protected $fillable = [
        'post_id',
        'target_id',
        'metric_date',
        'reach',
        'impressions',
        'likes',
        'comments',
        'shares',
        'saves',
        'clicks',
        'platform_metrics',
        'measured_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'post_id'          => 'integer',
        'target_id'        => 'integer',
        'reach'            => 'integer',
        'impressions'      => 'integer',
        'likes'            => 'integer',
        'comments'         => 'integer',
        'shares'           => 'integer',
        'saves'            => 'integer',
        'clicks'           => 'integer',
        'platform_metrics' => 'array',
        'measured_at'      => 'datetime',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(SocialPostTarget::class, 'target_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }
}
