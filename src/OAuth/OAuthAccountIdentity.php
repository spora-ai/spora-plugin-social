<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialPlatform;

/**
 * The account a connection covers, as the platform described it.
 *
 * `extra` carries the per-platform handle the publish call needs — a
 * LinkedIn author URN, an Instagram user id — so the adapters never
 * re-derive it from the access token.
 */
final readonly class OAuthAccountIdentity
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public SocialPlatform $platform,
        public string $externalId,
        public SocialAccountKind $kind,
        public ?string $displayName = null,
        public ?string $username = null,
        public ?int $followers = null,
        public array $extra = [],
    ) {}
}
