<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

use DateTimeImmutable;

/**
 * The tokens one platform handed out, in one shape.
 *
 * `extra` carries what only one provider has — Meta's client business id,
 * LinkedIn's `id_token` — so the shared code never has to know which
 * provider it is holding.
 *
 * @property-read list<string> $scopes
 * @property-read array<string, mixed> $extra
 */
final readonly class OAuthTokenSet
{
    /**
     * @param list<string>       $scopes
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?DateTimeImmutable $expiresAt = null,
        public array $scopes = [],
        public array $extra = [],
    ) {}
}
