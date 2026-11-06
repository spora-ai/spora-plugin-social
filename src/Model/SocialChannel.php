<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Model;

/**
 * Where a target goes out: the network, and the route to it.
 *
 * A target's identity is the pair, not the network: the same network
 * reached through two routes is two accounts with two tokens, and a post
 * that reaches both carries a copy of its own for each.
 *
 * `via` is the route — `direct` for the plugin's own OAuth and the
 * networks' own APIs, and the aggregator's own slug for the rest.
 */
final readonly class SocialChannel
{
    /**
     * The plugin's own route to a network.
     */
    public const DIRECT = 'direct';

    private function __construct(
        public string $via,
        public string $network,
    ) {}

    /**
     * The plugin's own route to a network.
     */
    public static function direct(string $network): self
    {
        return new self(self::DIRECT, self::normalise($network));
    }

    /**
     * A channel from its two parts.
     *
     * Null when a part is missing: half a channel names nothing, and a
     * target that names nothing is a target nothing can publish.
     */
    public static function tryFrom(string $via, string $network): ?self
    {
        $via = self::normalise($via);
        $network = self::normalise($network);

        if ($via === '' || $network === '') {
            return null;
        }

        return new self($via, $network);
    }

    /**
     * A channel from its key.
     *
     * A bare network name is the plugin's own route to it — the model
     * writes "linkedin" and means "direct:linkedin" — and a key names
     * the route and the network both.
     */
    public static function fromKey(string $key): ?self
    {
        $key = self::normalise($key);

        if ($key === '') {
            return null;
        }

        if (!str_contains($key, ':')) {
            return self::tryFrom(self::DIRECT, $key);
        }

        [$via, $network] = explode(':', $key, 2);

        return self::tryFrom($via, $network);
    }

    /**
     * The channel's own name: the route, then the network.
     */
    public function key(): string
    {
        return $this->via . ':' . $this->network;
    }

    /**
     * Whether this is the plugin's own route to the network.
     */
    public function isDirect(): bool
    {
        return $this->via === self::DIRECT;
    }

    private static function normalise(string $value): string
    {
        return strtolower(trim($value));
    }
}
