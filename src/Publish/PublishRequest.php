<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Spora\Plugins\Social\Model\SocialAccountKind;

/**
 * One publish attempt against one account.
 */
final readonly class PublishRequest
{
    public function __construct(
        public string $externalId,
        public SocialAccountKind $kind,
        public string $accessToken,
        public string $body,
        public ?PublishMedia $media = null,
    ) {}
}
