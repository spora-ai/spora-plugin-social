<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Core\Config;
use Spora\Plugins\Social\OAuth\LinkedInPermissions;

/**
 * The LinkedIn permissions the operator's app asks for.
 *
 * Each of them is opt-in: an app that asks for a permission it does not
 * hold is refused the whole authorization request, so the operator says
 * which ones their app has.
 *
 * The answer is one value object, because the login URL asks for them all
 * at once.
 */
final class LinkedInPermissionSettings
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function requested(): LinkedInPermissions
    {
        return new LinkedInPermissions(
            organizationScope: $this->config->getBool(SocialSettings::KEY_LINKEDIN_ORGANIZATION_SCOPE),
            readOrganizationAcls: $this->config->getBool(SocialSettings::KEY_LINKEDIN_READ_ORG_ACLS),
            readOrganizationStats: $this->config->getBool(SocialSettings::KEY_LINKEDIN_READ_ORG_STATS),
            readMemberFeed: $this->config->getBool(SocialSettings::KEY_LINKEDIN_READ_MEMBER_FEED),
            readOrganizationFeed: $this->config->getBool(SocialSettings::KEY_LINKEDIN_READ_ORG_FEED),
        );
    }
}
