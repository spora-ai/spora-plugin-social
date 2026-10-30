<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Model;

/**
 * The OAuth side of the plugin: one provider, one token, many accounts.
 *
 * A connection is what a platform calls a login. Meta's login is a business
 * portfolio: one system-user token covers every Instagram account and every
 * Page the client shared, so the token belongs to the connection and not to
 * an account. LinkedIn's login is a person: one token covers that member
 * and the organisations they administer.
 *
 * The accounts hang off the connection, and a publish reads the connection's
 * token.
 */
enum SocialProvider: string
{
    case Meta = 'meta';
    case LinkedIn = 'linkedin';

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
