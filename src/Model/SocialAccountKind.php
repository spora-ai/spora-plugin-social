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
 */
enum SocialAccountKind: string
{
    case Organization = 'organization';
    case Page = 'page';
    case Member = 'member';
    case Business = 'business';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
