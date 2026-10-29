<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Closure;
use Spora\Plugins\Social\Model\SocialAccountKind;

/**
 * One publish attempt against one account.
 */
final readonly class PublishRequest
{
    /**
     * @param Closure(string): void|null $onContainer Called with the container id as soon as the platform has
     *                                                 one. The caller writes it down, so a publish that dies
     *                                                 between the container and the publish leaves a trace to
     *                                                 reconcile.
     */
    public function __construct(
        public string $externalId,
        public SocialAccountKind $kind,
        public string $accessToken,
        public string $body,
        public ?PublishMedia $media = null,
        public ?Closure $onContainer = null,
    ) {}
}
