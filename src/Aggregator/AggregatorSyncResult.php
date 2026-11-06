<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

/**
 * What one sync of the aggregator's accounts found.
 */
final readonly class AggregatorSyncResult
{
    /**
     * @param list<AggregatorAccount> $accounts the accounts the aggregator reports
     * @param list<string>            $gone     the accounts it no longer reports
     * @param array<string, int>      $followers the follower counts, by account
     */
    public function __construct(
        public array $accounts,
        public array $gone,
        public array $followers,
        public bool $analyticsAvailable,
    ) {}
}
