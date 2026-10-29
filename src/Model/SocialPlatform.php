<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Model;

/**
 * The three networks the plugin publishes to.
 *
 * A backed enum rather than a string column: the platform decides which
 * adapter runs, which fit rules apply and which insights API is read, and
 * a typo in a platform name would otherwise be a runtime surprise instead
 * of a type error.
 */
enum SocialPlatform: string
{
    case LinkedIn = 'linkedin';
    case Facebook = 'facebook';
    case Instagram = 'instagram';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom(strtolower(trim($name)));
    }
}
