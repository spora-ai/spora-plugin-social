<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

/**
 * The LinkedIn permissions the operator's app asks for.
 *
 * The restricted ones are opt-in: an app that asks for a permission it does
 * not hold is refused the whole authorization request, so the operator says
 * which ones their app has.
 *
 * The member ones are on by default, because they are open permissions and
 * every app has them — and off when the app does not. An app holding only
 * the Community Management API product cannot serve OpenID Connect, and
 * asking for it costs the whole request, member posting included.
 */
final class LinkedInPermissions
{
    public function __construct(
        public readonly bool $memberScope = true,
        public readonly bool $signInScope = true,
        public readonly bool $organizationScope = false,
        public readonly bool $readOrganizationAcls = false,
        public readonly bool $readOrganizationStats = false,
        public readonly bool $readMemberFeed = false,
        public readonly bool $readOrganizationFeed = false,
    ) {}
}
