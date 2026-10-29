<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialPlatform;

/**
 * Whether an account has numbers to report, and why not when it has not.
 *
 * Two facts decide it, and they are of different kinds:
 *
 * - **What the platform counts.** A LinkedIn member post has reactions
 *   and nothing else; a personal Instagram account has no insights at
 *   all. No token changes that.
 * - **What the token may read.** The insights permissions are scopes on
 *   the token, not properties of the account: an Instagram account whose
 *   token does not carry `instagram_manage_insights` genuinely cannot
 *   report insights.
 *
 * The note is computed here, once, because the scopes live on the
 * connection and the connections grid is not the only reader.
 */
final class AccountReporting
{
    public const MEMBER_NOTE = 'Member posts report reactions only — no impressions.';

    public const PERSONAL_INSTAGRAM_NOTE = 'Personal accounts have no media insights.';

    public const MEMBER_REACTIONS_NOTE = 'This connection\'s token does not carry the r_member_social_feed permission, so the reactions on a member post cannot be read.';

    public const ORGANIZATION_STATS_NOTE = 'This connection\'s token does not carry the rw_organization_admin permission, so the organisation\'s share statistics cannot be read.';

    public const PAGE_INSIGHTS_NOTE = 'This connection\'s token does not carry the read_insights permission, so the Page\'s numbers cannot be read.';

    public const INSTAGRAM_INSIGHTS_NOTE = 'This connection\'s token does not carry the instagram_manage_insights permission, so the account\'s media insights cannot be read.';

    /**
     * The scope that reads an account's numbers, per platform and kind.
     *
     * @var array<string, string>
     */
    private const SCOPES = [
        'linkedin|member'       => 'r_member_social_feed',
        'linkedin|organization' => 'rw_organization_admin',
        'facebook|page'         => 'read_insights',
        'instagram|business'    => 'instagram_manage_insights',
    ];

    /**
     * What the missing scope means, per scope.
     *
     * @var array<string, string>
     */
    private const SCOPE_NOTES = [
        'r_member_social_feed'     => self::MEMBER_REACTIONS_NOTE,
        'rw_organization_admin'    => self::ORGANIZATION_STATS_NOTE,
        'read_insights'            => self::PAGE_INSIGHTS_NOTE,
        'instagram_manage_insights' => self::INSTAGRAM_INSIGHTS_NOTE,
    ];

    /**
     * What the account can report, and why it cannot when it cannot.
     *
     * @param  list<string> $grantedScopes the connection's granted scopes
     * @return array{can_report: bool, report_note: ?string}
     */
    public static function of(SocialAccount $account, array $grantedScopes): array
    {
        $key = $account->platform . '|' . $account->kind;

        // A personal Instagram account has no insights at all: no scope
        // gives it any.
        if ($account->platform === SocialPlatform::Instagram->value
            && $account->kind === SocialAccountKind::Member->value
        ) {
            return ['can_report' => false, 'report_note' => self::PERSONAL_INSTAGRAM_NOTE];
        }

        $scope = self::SCOPES[$key] ?? null;

        // The scopes are only known when the platform reported them. An
        // empty list means "not known", not "not granted" — a Meta
        // system-user token reports its permissions through the
        // configuration, not through the token response — so a missing
        // scope is only a refusal when the platform named the scopes.
        if ($scope !== null && $grantedScopes !== [] && !in_array($scope, $grantedScopes, true)) {
            return ['can_report' => false, 'report_note' => self::SCOPE_NOTES[$scope]];
        }

        // A LinkedIn member post reports reactions and nothing else.
        if ($account->platform === SocialPlatform::LinkedIn->value
            && $account->kind === SocialAccountKind::Member->value
        ) {
            return ['can_report' => false, 'report_note' => self::MEMBER_NOTE];
        }

        return ['can_report' => true, 'report_note' => null];
    }
}
