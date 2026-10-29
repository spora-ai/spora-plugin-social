<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One login: a provider, the token it handed out, and the accounts it covers.
 *
 * The token is stored encrypted with core's security manager — the same
 * mechanism the settings use for secrets — and is decrypted only on the way
 * to a platform.
 *
 * `business_external_id` is the portfolio the connection belongs to: Meta's
 * client business id, which the token management API needs. It is null where
 * the platform has no portfolio — LinkedIn's connection belongs to a person,
 * and the person is one of the accounts.
 *
 * `expires_at` is null where the platform sent no expiry: a Meta
 * system-user token "defaults to never expire", and the configuration
 * chooses the rest.
 *
 * @property int $id
 * @property int $principal_id
 * @property string $provider
 * @property string|null $business_external_id
 * @property string|null $config_id
 * @property string $access_token Ciphertext.
 * @property string|null $refresh_token Ciphertext.
 * @property \Carbon\Carbon|null $expires_at
 * @property string $status
 * @property string|null $scopes
 * @property ?\Carbon\Carbon $created_at
 * @property ?\Carbon\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SocialAccount> $accounts
 */
final class SocialConnection extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_ERROR = 'error';

    protected $table = 'social_connections';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'business_external_id',
        'config_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'status',
        'scopes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'principal_id' => 'integer',
        'expires_at'   => 'datetime',
    ];

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class, 'connection_id');
    }
}
