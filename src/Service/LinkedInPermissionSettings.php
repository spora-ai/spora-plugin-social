<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Core\Config;
use Spora\Plugins\Social\OAuth\LinkedInPermissions;

/**
 * The permissions the operator's app asks for, read from the settings.
 *
 * See {@see LinkedInProvider} for why these default to on.
 */
final class LinkedInPermissionSettings
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function requested(): LinkedInPermissions
    {
        return new LinkedInPermissions(
            memberScope: $this->config->getBool(SocialSettings::KEY_LINKEDIN_MEMBER_SCOPE, true),
            signInScope: $this->config->getBool(SocialSettings::KEY_LINKEDIN_SIGN_IN_SCOPE, true),
            organizationScope: $this->config->getBool(SocialSettings::KEY_LINKEDIN_ORGANIZATION_SCOPE),
            readOrganizationAcls: $this->config->getBool(SocialSettings::KEY_LINKEDIN_READ_ORG_ACLS),
            readOrganizationStats: $this->config->getBool(SocialSettings::KEY_LINKEDIN_READ_ORG_STATS),
            readMemberFeed: $this->config->getBool(SocialSettings::KEY_LINKEDIN_READ_MEMBER_FEED),
            readOrganizationFeed: $this->config->getBool(SocialSettings::KEY_LINKEDIN_READ_ORG_FEED),
        );
    }
}
