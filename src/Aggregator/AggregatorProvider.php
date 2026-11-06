<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

/**
 * The aggregators the plugin can publish through.
 *
 * An aggregator is not an OAuth provider: it holds one API key for the
 * whole team, and the accounts it covers are discovered rather than
 * connected. The slug is also the route a target on it takes, so a target
 * on Zernio is `zernio:bluesky`.
 */
enum AggregatorProvider: string
{
    case Zernio = 'zernio';

    /**
     * The name the operator knows the aggregator by.
     *
     * Zernio is a product, not a category: the operator knows which
     * aggregator they bought, so the screen names it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Zernio => 'Zernio',
        };
    }

    /**
     * The route a target on this aggregator takes.
     *
     * The same as the slug: an aggregator is its own route, and a target
     * on it is `{via}:{network}`.
     */
    public function route(): string
    {
        return $this->value;
    }

    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom(strtolower(trim($name)));
    }
}
