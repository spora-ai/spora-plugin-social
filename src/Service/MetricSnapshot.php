<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

/**
 * One target's numbers, normalised.
 *
 * A counter the platform does not report is null, not zero: a LinkedIn
 * member post has no impressions, and a zero there reads as "nobody saw
 * it" rather than "this platform does not count that".
 */
final readonly class MetricSnapshot
{
    /**
     * The counters every platform is normalised into.
     *
     * One list, so the reader, the aggregates and the wire shape cannot
     * disagree about which counters exist.
     *
     * @var list<string>
     */
    public const COUNTERS = ['reach', 'impressions', 'likes', 'comments', 'shares', 'saves', 'clicks'];

    /**
     * @param array<string, mixed> $platformMetrics
     */
    public function __construct(
        public ?int $reach = null,
        public ?int $impressions = null,
        public ?int $likes = null,
        public ?int $comments = null,
        public ?int $shares = null,
        public ?int $saves = null,
        public ?int $clicks = null,
        public array $platformMetrics = [],
        public ?string $note = null,
    ) {}
}
