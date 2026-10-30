<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Settings;

use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Service\ApiVersions;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Settings\PluginSettingsProviderInterface;
use Spora\Settings\SettingDefinition;

/**
 * The operator settings the plugin needs.
 *
 * The keys are given without the `SPORA_SOCIAL_` prefix: the registry
 * builds the full name, so the plugin states one key and the host owns
 * the namespace.
 *
 * The client secrets are secret settings — stored encrypted, never sent
 * over the wire — because they are the credential the operator's app
 * gives the platform.
 */
final class SocialSettingsProvider implements PluginSettingsProviderInterface
{
    /**
     * The caveat the restricted LinkedIn permissions carry.
     *
     * Three of the permissions are granted to selected developers only, so
     * asking for one the app does not hold refuses the whole authorization
     * request — not only the part that needs the permission.
     */
    private const LINKEDIN_RESTRICTED_PERMISSION = 'LinkedIn grants it to selected developers only, and an app that asks for a permission it does not hold is refused the whole authorization request — so this is off unless your app has it.';

    /**
     * The settings are read where the operator configures the app, so the
     * redirect URL the platform has to be given belongs in the description
     * of the setting it is registered on.
     */
    public function __construct(
        private readonly SocialSettings $settings,
    ) {}

    public function slug(): string
    {
        return 'social';
    }

    /**
     * The Meta configuration id, with the redirect URL to register on it.
     *
     * The configuration is where the redirect URL is registered — under
     * *Facebook Login for Business → Configurations* — so the instruction
     * belongs here, where the operator is creating the configuration, and
     * not on the accounts screen, where it is read as configuration.
     *
     * The URL is the one the OAuth request carries, so the two can never
     * disagree about it.
     */
    private function metaConfigurationDescription(): string
    {
        $redirect = $this->settings->oauthRedirectUri(SocialProvider::Meta);

        if ($redirect === null || !$this->settings->hasPublicAddress()) {
            return 'The configuration id from your Meta app\'s Facebook Login for Business → Configurations. This instance has no public address, so there is no redirect URL to register.';
        }

        return sprintf(
            'The configuration id from your Meta app\'s Facebook Login for Business → Configurations. Register this redirect URL with it: %s',
            $redirect,
        );
    }

    /**
     * The LinkedIn client id, with the redirect URL to register on it.
     *
     * @see self::metaConfigurationDescription() for why the URL lives here
     */
    private function linkedInClientIdDescription(): string
    {
        $redirect = $this->settings->oauthRedirectUri(SocialProvider::LinkedIn);

        if ($redirect === null || !$this->settings->hasPublicAddress()) {
            return 'Your LinkedIn app. Member posting needs no review; organisation pages need the Community Management API. This instance has no public address, so there is no redirect URL to register.';
        }

        return sprintf(
            'Your LinkedIn app. Member posting needs no review; organisation pages need the Community Management API. Register this redirect URL with it: %s',
            $redirect,
        );
    }

    /**
     * A setting the operator turns on or off.
     *
     * The five LinkedIn permissions are all of the same shape, and a
     * `SettingDefinition` written out five times is a shape that can
     * drift.
     */
    private function toggle(string $group, string $key, string $label, string $description): SettingDefinition
    {
        return new SettingDefinition(
            key: $key,
            label: $label,
            type: SettingDefinition::TYPE_TOGGLE,
            default: false,
            group: $group,
            section: 'social',
            description: $description,
        );
    }

    /**
     * @return list<SettingDefinition>
     */
    public function settings(): array
    {
        $group = 'plugin:' . $this->slug();

        return [
            new SettingDefinition(
                key: 'LINKEDIN_CLIENT_ID',
                label: 'LinkedIn client id',
                type: SettingDefinition::TYPE_TEXT,
                default: '',
                group: $group,
                section: 'social',
                description: $this->linkedInClientIdDescription(),
            ),
            new SettingDefinition(
                key: 'LINKEDIN_CLIENT_SECRET',
                label: 'LinkedIn client secret',
                type: SettingDefinition::TYPE_PASSWORD,
                default: '',
                group: $group,
                section: 'social',
                description: 'The client secret of your LinkedIn app. Stored encrypted.',
                secret: true,
            ),
            $this->toggle(
                $group,
                'LINKEDIN_ORGANIZATION_SCOPE',
                'Ask LinkedIn for organisation pages',
                'Ask for w_organization_social, the permission that posts on behalf of an organisation. '
                    . 'Turn this on only once your app has the Community Management API approved. '
                    . 'Asking for a scope the app does not hold makes the whole authorization request fail.',
            ),
            $this->toggle(
                $group,
                'LINKEDIN_READ_ORG_ACLS',
                'Ask LinkedIn for the administered organisations',
                'Ask for r_organization_admin, the permission that finds the organisations the member administers. '
                    . self::LINKEDIN_RESTRICTED_PERMISSION,
            ),
            $this->toggle(
                $group,
                'LINKEDIN_READ_ORG_STATS',
                'Ask LinkedIn for the organisation statistics',
                'Ask for rw_organization_admin, the permission that reads an organisation\'s share statistics. '
                    . self::LINKEDIN_RESTRICTED_PERMISSION,
            ),
            $this->toggle(
                $group,
                'LINKEDIN_READ_MEMBER_FEED',
                'Ask LinkedIn for the member post reactions',
                'Ask for r_member_social_feed, the permission that reads a member post\'s reactions. '
                    . self::LINKEDIN_RESTRICTED_PERMISSION,
            ),
            $this->toggle(
                $group,
                'LINKEDIN_READ_ORG_FEED',
                'Ask LinkedIn for the organisation post reactions',
                'Ask for r_organization_social_feed, the permission that reads an organisation post\'s reactions. '
                    . 'LinkedIn restricts it to members who administer the organisation, and an app that asks for a permission '
                    . 'it does not hold is refused the whole authorization request — so this is off unless your app has it.',
            ),
            new SettingDefinition(
                key: 'LINKEDIN_API_VERSION',
                label: 'LinkedIn API version',
                type: SettingDefinition::TYPE_TEXT,
                default: ApiVersions::DEFAULT_LINKEDIN,
                group: $group,
                section: 'social',
                description: 'The LinkedIn API version, in YYYYMM. LinkedIn versions its APIs quarterly and each version lives two years, '
                    . 'so a fixed one stops working on a date nobody chose.',
            ),
            new SettingDefinition(
                key: 'GRAPH_API_VERSION',
                label: 'Meta Graph API version',
                type: SettingDefinition::TYPE_TEXT,
                default: ApiVersions::DEFAULT_GRAPH,
                group: $group,
                section: 'social',
                description: 'The Graph API version every Meta endpoint carries, written the way the URL wants it, '
                    . 'e.g. v26.0. Meta retires a version every year, so a fixed one stops working on a date nobody chose.',
            ),
            new SettingDefinition(
                key: 'META_APP_ID',
                label: 'Meta app id',
                type: SettingDefinition::TYPE_TEXT,
                default: '',
                group: $group,
                section: 'social',
                description: 'The app id of your Meta app. One app serves both networks: the Instagram API with '
                    . 'Unified Login is built on Facebook Login for Business. Pages need the app reviewed before it '
                    . 'can publish.',
            ),
            new SettingDefinition(
                key: 'META_APP_SECRET',
                label: 'Meta app secret',
                type: SettingDefinition::TYPE_PASSWORD,
                default: '',
                group: $group,
                section: 'social',
                description: 'The app secret of your Meta app. Stored encrypted.',
                secret: true,
            ),
            new SettingDefinition(
                key: 'META_CONFIG_ID',
                label: 'Meta configuration id',
                type: SettingDefinition::TYPE_TEXT,
                default: '',
                group: $group,
                section: 'social',
                description: $this->metaConfigurationDescription(),
            ),
            new SettingDefinition(
                key: 'FRONTEND_URL',
                label: 'Frontend URL',
                type: SettingDefinition::TYPE_TEXT,
                default: '',
                group: $group,
                section: 'social',
                description: 'Where the browser is sent after an account is connected. '
                    . 'Leave it empty unless the SPA runs on another origin, as it does in local development.',
            ),
        ];
    }
}
