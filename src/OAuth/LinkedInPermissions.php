<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

/**
 * The LinkedIn permissions the operator's app asks for.
 *
 * Each of them is opt-in: an app that asks for a permission it does not
 * hold is refused the whole authorization request, so the operator says
 * which ones their app has.
 */
final class LinkedInPermissions
{
    public function __construct(
        public readonly bool $organizationScope = false,
        public readonly bool $readOrganizationAcls = false,
        public readonly bool $readOrganizationStats = false,
        public readonly bool $readMemberFeed = false,
        public readonly bool $readOrganizationFeed = false,
    ) {}
}
