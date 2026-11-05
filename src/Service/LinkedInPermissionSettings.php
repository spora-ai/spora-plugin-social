<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Core\Config;
use Spora\Plugins\Social\OAuth\LinkedInPermissions;

/**
 * The LinkedIn permissions the operator's app asks for.
 *
 * The restricted ones are opt-in: an app that asks for a permission it does
 * not hold is refused the whole authorization request, so the operator says
 * which ones their app has.
 *
 * The member ones are on unless the operator says otherwise, and they are
 * two permissions rather than one: an app holding the Community Management
 * API product holds w_member_social but cannot serve OpenID Connect, so
 * there the operator turns the sign-in permission off and leaves the member
 * one on.
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
