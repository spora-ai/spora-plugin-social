<?php

declare(strict_types=1);

use Spora\Plugins\Social\Service\AccountReporting;

/**
 * Whether an account has numbers to report, and why not when it has not.
 *
 * The two facts are of different kinds: what the platform counts, and
 * what the token may read.
 */

/**
 * The report of an account of the given kind.
 *
 * @param  list<string> $scopes the scopes the connection's token carries
 * @return array{can_report: bool, report_note: ?string}
 */
function accountReport(string $platform, string $kind, array $scopes = [], ?int $principalId = null): array
{
    $principalId ??= createUserPrincipal(bootAuth(bootAuthLayer()));

    return AccountReporting::of(createSocialAccount($principalId, $platform, $kind), $scopes);
}

it('says that a linkedin member reports reactions only', function () {
    $reporting = accountReport('linkedin', 'member');

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toBe('Member posts report reactions only — no impressions.');
});

it('says that a personal instagram account has no media insights', function () {
    $reporting = accountReport('instagram', 'member');

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toBe('Personal accounts have no media insights.');
});

it('says that an instagram account without the insights permission cannot report', function () {
    $reporting = accountReport('instagram', 'business', ['instagram_basic', 'instagram_content_publish']);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toContain('instagram_manage_insights');
});

it('says that an organisation without the admin permission cannot report', function () {
    $reporting = accountReport('linkedin', 'organization', ['w_member_social']);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toContain('rw_organization_admin');
});

it('says that a page without the insights permission cannot report', function () {
    $reporting = accountReport('facebook', 'page', ['pages_manage_posts']);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toContain('read_insights');
});

it('says that a member without the reactions permission cannot report', function () {
    $reporting = accountReport('linkedin', 'member', ['openid', 'profile']);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toContain('r_member_social_feed');
});

it('reports an account that holds the permission as able to report', function () {
    $scopes = ['instagram_manage_insights', 'rw_organization_admin', 'read_insights'];
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    foreach (['instagram|business', 'linkedin|organization', 'facebook|page'] as $account) {
        [$platform, $kind] = explode('|', $account);

        $reporting = accountReport($platform, $kind, $scopes, $principalId);

        expect($reporting['can_report'])->toBeTrue()
            ->and($reporting['report_note'])->toBeNull();
    }
});

it('does not claim a permission is missing when the scopes were never reported', function () {
    // A Meta system-user token reports its permissions through the
    // configuration, not through the token response — so an empty scope
    // list means "not known", not "not granted".
    $reporting = accountReport('instagram', 'business');

    expect($reporting['can_report'])->toBeTrue()
        ->and($reporting['report_note'])->toBeNull();
});
