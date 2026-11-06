<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

/**
 * One account an aggregator holds.
 *
 * The aggregator names the account and the network it publishes to; the
 * plugin owns the rest.
 */
final readonly class AggregatorAccount
{
    public function __construct(
        public string $externalId,
        public string $network,
        public ?string $displayName = null,
        public ?string $username = null,
        /**
         * The kind the aggregator itself reports, when it reports one.
         *
         * Null when it does not, and the network's own table decides then.
         */
        public ?string $kind = null,
        /**
         * The follower count the account object carries.
         *
         * Null when it does not carry one, and the count is then fetched.
         * The aggregator puts it there only when the plan carries the
         * analytics add-on, so a count on the object is also the answer
         * to whether the plan has it.
         */
        public ?int $followers = null,
    ) {}
}
