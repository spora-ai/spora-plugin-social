<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A post — one piece of copy, aimed at a set of accounts.
 *
 * The `status` column is derived from the post's targets and never written
 * on its own: a post-level status that could be set independently would be
 * able to claim a publish that did not happen.
 *
 * @property int $id
 * @property int $principal_id
 * @property string|null $title Internal label, never published.
 * @property string|null $body
 * @property array<string, string>|null $platform_bodies
 * @property string $status
 * @property string $source
 * @property \Carbon\Carbon|null $scheduled_for
 * @property string $timezone
 * @property \Carbon\Carbon|null $published_at
 * @property ?\Carbon\Carbon $created_at
 * @property ?\Carbon\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SocialPostTarget> $targets
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SocialPostMedia> $media
 */
final class SocialPost extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_MANUAL = 'manual';

    /**
     * The agent wrote it.
     *
     * The only source that is not the operator: an agent-driven post is
     * the mode the plugin exists for, and the source is what tells the
     * operator which posts a person chose and which ones an agent chose.
     */
    public const SOURCE_AI = 'ai';

    protected $table = 'social_posts';

    /** @var list<string> */
    protected $fillable = [
        'title',
        'body',
        'platform_bodies',
        'source',
        'scheduled_for',
        'timezone',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'principal_id'  => 'integer',
        'platform_bodies' => 'array',
        'scheduled_for' => 'datetime',
        'published_at'  => 'datetime',
    ];

    /**
     * @return HasMany<SocialPostTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class, 'post_id');
    }

    /**
     * @return HasMany<SocialPostMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(SocialPostMedia::class, 'post_id');
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(\Spora\Models\Principal::class);
    }

    /**
     * @param Builder<SocialPost> $query
     * @return Builder<SocialPost>
     */
    public function scopeForPrincipal(Builder $query, int $principalId): Builder
    {
        return $query->where('principal_id', $principalId);
    }
}
