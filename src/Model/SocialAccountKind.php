<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Model;

/**
 * What kind of account on the platform the row stands for.
 *
 * The kind decides what the account can report: an organisation page has
 * impressions, a LinkedIn member post does not, and an Instagram personal
 * account has no media insights at all. The account's own note says so
 * before the operator wonders why the numbers are empty.
 *
 * `aggregator` is the fallback for a network the plugin does not reach
 * natively: the account is a Bluesky account or a TikTok account, seen
 * through somebody else's API. The route is a separate axis — a Zernio
 * Facebook account is still a Facebook Page.
 */
enum SocialAccountKind: string
{
    case Organization = 'organization';
    case Page = 'page';
    case Member = 'member';
    case Business = 'business';
    case Aggregator = 'aggregator';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
