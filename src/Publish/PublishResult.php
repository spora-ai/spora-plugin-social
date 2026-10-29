<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

/**
 * What one platform made of a publish attempt.
 *
 * A failure carries the platform's own error text: the adapter is the only
 * place that knows what the platform said, and a generic "publish failed"
 * leaves the operator nothing to act on.
 */
final readonly class PublishResult
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public bool $success,
        public ?string $externalId = null,
        public ?string $externalUrl = null,
        public ?string $error = null,
        public array $extra = [],
        /**
         * The container the platform prepared the media in, where the
         * platform has one.
         *
         * Instagram creates a container and publishes it in a second call,
         * and the container is what a publish that half-finished can be
         * checked against and resumed from — `GET /{container}?fields=status_code`.
         * The container expires after 24 hours, so the id is worth keeping
         * for exactly that long.
         */
        public ?string $containerId = null,
    ) {}

    /**
     * @param array<string, mixed> $extra
     */
    public static function published(
        string $externalId,
        ?string $externalUrl = null,
        array $extra = [],
        ?string $containerId = null,
    ): self {
        return new self(true, $externalId, $externalUrl, null, $extra, $containerId);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, null, $error);
    }
}
