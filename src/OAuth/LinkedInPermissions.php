<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

/**
 * The LinkedIn permissions the operator's app asks for.
 *
 * See {@see LinkedInProvider} for which of them an app may be asked for, and
 * why the defaults differ.
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
