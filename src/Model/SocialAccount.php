<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One connected account on one platform.
 *
 * `$fillable` excludes every server-assigned column — `id`, `principal_id`,
 * `status`, the timestamps — because identity must be structurally
 * unwritable from a request body.
 *
 * @property int $id
 * @property int $principal_id
 * @property int|null $connection_id
 * @property string $platform
 * @property string $kind
 * @property string $external_id
 * @property string|null $display_name
 * @property string|null $username
 * @property string $status
 * @property int|null $followers
 * @property \Carbon\Carbon|null $connected_at
 * @property ?\Carbon\Carbon $created_at
 * @property ?\Carbon\Carbon $updated_at
 * @property-read SocialConnection|null $connection
 */
final class SocialAccount extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_ERROR = 'error';

    protected $table = 'social_accounts';

    /** @var list<string> */
    protected $fillable = [
        'platform',
        'kind',
        'external_id',
        'display_name',
        'username',
        'followers',
        'connected_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'principal_id'  => 'integer',
        'connection_id' => 'integer',
        'followers'     => 'integer',
        'connected_at'  => 'datetime',
    ];

    /**
     * The login this account belongs to.
     *
     * The token lives there: under a Meta system-user token one token
     * covers every Instagram account and every Page the client shared.
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'connection_id');
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(\Spora\Models\Principal::class);
    }

    /**
     * @param Builder<SocialAccount> $query
     * @return Builder<SocialAccount>
     */
    public function scopeForPrincipal(Builder $query, int $principalId): Builder
    {
        return $query->where('principal_id', $principalId);
    }
}
