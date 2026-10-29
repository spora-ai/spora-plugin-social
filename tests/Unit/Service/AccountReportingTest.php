<?php

declare(strict_types=1);

use Spora\Plugins\Social\Service\AccountReporting;

/**
 * Whether an account has numbers to report, and why not when it has not.
 *
 * The two facts are of different kinds: what the platform counts, and
 * what the token may read.
 */
it('says that a linkedin member reports reactions only', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = createSocialAccount($principalId, 'linkedin', 'member');

    $reporting = AccountReporting::of($account, []);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toBe('Member posts report reactions only — no impressions.');
});

it('says that a personal instagram account has no media insights', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = createSocialAccount($principalId, 'instagram', 'member');

    $reporting = AccountReporting::of($account, []);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toBe('Personal accounts have no media insights.');
});

it('says that an instagram account without the insights permission cannot report', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = createSocialAccount($principalId, 'instagram', 'business');

    $reporting = AccountReporting::of($account, ['instagram_basic', 'instagram_content_publish']);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toContain('instagram_manage_insights');
});

it('says that an organisation without the admin permission cannot report', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = createSocialAccount($principalId, 'linkedin', 'organization');

    $reporting = AccountReporting::of($account, ['w_member_social']);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toContain('rw_organization_admin');
});

it('says that a page without the insights permission cannot report', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = createSocialAccount($principalId, 'facebook', 'page');

    $reporting = AccountReporting::of($account, ['pages_manage_posts']);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toContain('read_insights');
});

it('says that a member without the reactions permission cannot report', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = createSocialAccount($principalId, 'linkedin', 'member');

    $reporting = AccountReporting::of($account, ['openid', 'profile']);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toContain('r_member_social_feed');
});

it('reports an account that holds the permission as able to report', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $instagram = createSocialAccount($principalId, 'instagram', 'business');
    $organisation = createSocialAccount($principalId, 'linkedin', 'organization');
    $page = createSocialAccount($principalId, 'facebook', 'page');

    $scopes = ['instagram_manage_insights', 'rw_organization_admin', 'read_insights'];

    foreach ([$instagram, $organisation, $page] as $account) {
        $reporting = AccountReporting::of($account, $scopes);

        expect($reporting['can_report'])->toBeTrue()
            ->and($reporting['report_note'])->toBeNull();
    }
});

it('does not claim a permission is missing when the scopes were never reported', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    // A Meta system-user token reports its permissions through the
    // configuration, not through the token response — so an empty scope
    // list means "not known", not "not granted".
    $account = createSocialAccount($principalId, 'instagram', 'business');

    $reporting = AccountReporting::of($account, []);

    expect($reporting['can_report'])->toBeTrue()
        ->and($reporting['report_note'])->toBeNull();
});
