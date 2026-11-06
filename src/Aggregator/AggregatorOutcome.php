<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

/**
 * What the aggregator made of one target.
 *
 * Three outcomes, and the middle one is the point: a publish that was
 * still working is **not** a failure. Zernio retries it by itself, and
 * the target stays in `publishing` with the key that was used.
 */
final readonly class AggregatorOutcome
{
    private function __construct(
        public bool $success,
        public ?string $postId = null,
        public ?string $postUrl = null,
        public ?string $error = null,
        public bool $pending = false,
    ) {}

    /**
     * The post went out on that network.
     */
    public static function published(?string $postId, ?string $postUrl): self
    {
        return new self(true, $postId, $postUrl);
    }

    /**
     * The aggregator refused it, and said why.
     */
    public static function failed(string $error): self
    {
        return new self(false, null, null, $error);
    }

    /**
     * The aggregator is still working on it.
     */
    public static function pending(?string $error = null): self
    {
        return new self(false, null, null, $error, true);
    }
}
