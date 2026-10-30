<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attachment on one post.
 *
 * `position` is the order the attachments are published in. v1 posts a
 * single attachment, so the column exists for the carousel work rather than
 * for a feature in use.
 *
 * @property int $id
 * @property int $post_id
 * @property string $media_asset_id
 * @property int $position
 * @property string|null $derivative_format
 * @property ?\Carbon\Carbon $created_at
 * @property ?\Carbon\Carbon $updated_at
 */
final class SocialPostMedia extends Model
{
    protected $table = 'social_post_media';

    /** @var list<string> */
    protected $fillable = [
        'media_asset_id',
        'position',
        'derivative_format',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'post_id'   => 'integer',
        'position'  => 'integer',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }
}
