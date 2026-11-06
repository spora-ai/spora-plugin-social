<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Plugins\Social\Aggregator\AggregatorProvider;
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
 *
 * **An aggregator's account is answered differently.** Whether it can
 * report is the aggregator's plan and the network's own counting, not a
 * scope Spora granted: no scope of ours reaches Zernio's analytics.
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
     * The aggregator's numbers are an add-on on its plan.
     */
    public const AGGREGATOR_ADDON_NOTE = 'Analytics is an add-on on %s\'s plan, and this connection\'s plan does not carry it, so the numbers stay empty.';

    /**
     * Google reports at the location's level, not per post.
     */
    public const AGGREGATOR_LOCATION_NOTE = 'Google Business Profile reports at the location\'s level — Google deprecated the per-post numbers.';

    /**
     * The scope that reads an account's numbers, per network and kind.
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
        $note = self::noteFor($account, $grantedScopes);

        return ['can_report' => $note === null, 'report_note' => $note];
    }

    /**
     * Why the account cannot report, if it cannot.
     *
     * @param  list<string> $grantedScopes the connection's granted scopes
     */
    private static function noteFor(SocialAccount $account, array $grantedScopes): ?string
    {
        // An aggregator's account reports on the aggregator's plan, not on
        // a scope Spora granted: no scope of ours reaches Zernio's
        // analytics.
        if (!$account->channel()->isDirect()) {
            return self::aggregatorNote($account);
        }

        $scope = self::SCOPES[$account->channel()->network . '|' . $account->kind] ?? null;

        // The scopes are only known when the platform reported them. An
        // empty list means "not known", not "not granted" — a Meta
        // system-user token reports its permissions through the
        // configuration, not through the token response — so a missing
        // scope is only a refusal when the platform named the scopes.
        if ($scope !== null && $grantedScopes !== [] && !in_array($scope, $grantedScopes, true)) {
            return self::SCOPE_NOTES[$scope];
        }

        return self::platformNote($account);
    }

    /**
     * Why an aggregator's account cannot report, if it cannot.
     *
     * Two facts, and neither is a scope: the network counts what it
     * counts, and the aggregator's plan decides whether the numbers are
     * read at all.
     */
    private static function aggregatorNote(SocialAccount $account): ?string
    {
        // Google deprecated the per-post numbers: a Google Business
        // account reports at the location's level.
        if ($account->channel()->network === 'googlebusiness') {
            return self::AGGREGATOR_LOCATION_NOTE;
        }

        if ($account->connection?->analytics_available === false) {
            $label = AggregatorProvider::tryFromName((string) $account->connection->provider)?->label() ?? 'the aggregator';

            return sprintf(self::AGGREGATOR_ADDON_NOTE, $label);
        }

        return null;
    }

    /**
     * The note the platform itself decides: no scope gives these accounts
     * numbers.
     */
    private static function platformNote(SocialAccount $account): ?string
    {
        $network = $account->channel()->network;

        $isMember = $account->kind === SocialAccountKind::Member->value;

        // A personal Instagram account has no insights at all: no scope
        // gives it any.
        if ($isMember && $network === SocialPlatform::Instagram->value) {
            return self::PERSONAL_INSTAGRAM_NOTE;
        }

        // A LinkedIn member post reports reactions and nothing else.
        if ($isMember && $network === SocialPlatform::LinkedIn->value) {
            return self::MEMBER_NOTE;
        }

        return null;
    }
}
