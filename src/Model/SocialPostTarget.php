<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account's outcome of one post.
 *
 * @property int $id
 * @property int $post_id
 * @property int $account_id
 * @property string $platform
 * @property string $status
 * @property string|null $external_id
 * @property string|null $external_url
 * @property string|null $container_id
 * @property string|null $error
 * @property \Carbon\Carbon|null $published_at
 * @property ?\Carbon\Carbon $created_at
 * @property ?\Carbon\Carbon $updated_at
 * @property-read SocialAccount|null $account
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SocialPostMetric> $metrics
 */
final class SocialPostTarget extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    /**
     * The post went out and has since been taken back down.
     *
     * Not a failure: the publish happened, and the platform's own delete
     * brought it down again.
     */
    public const STATUS_DELETED = 'deleted';

    protected $table = 'social_post_targets';

    /** @var list<string> */
    protected $fillable = [
        'account_id',
        'platform',
        'status',
        'external_id',
        'external_url',
        'container_id',
        'error',
        'published_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'post_id'      => 'integer',
        'account_id'   => 'integer',
        'published_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<SocialPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }

    /**
     * @return HasMany<SocialPostMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(SocialPostMetric::class, 'target_id');
    }
}
