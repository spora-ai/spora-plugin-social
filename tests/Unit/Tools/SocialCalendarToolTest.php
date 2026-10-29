<?php

declare(strict_types=1);

use Spora\Plugins\Social\Tools\SocialCalendarTool;

function calendarTool(): SocialCalendarTool
{
    return new SocialCalendarTool(postService());
}

it('lists the posts of a month with their dates', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = postService()->create($principalId, [
        'title'         => 'Q4 release',
        'body'          => 'We shipped…',
        'account_ids'   => [$account->id],
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]);

    $result = calendarTool()->execute(
        ['action' => 'list_month', 'month' => '2026-11'],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeTrue()
        ->and($result->data['month'])->toBe('2026-11')
        ->and($result->data['posts'])->toHaveCount(1)
        ->and($result->data['posts'][0]['post_id'])->toBe($post->id)
        ->and($result->data['posts'][0]['scheduled_for'])->toStartWith('2026-11-02');
});

it('leaves the posts of another month out', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    postService()->create($principalId, [
        'title'         => 'Q4 release',
        'body'          => 'We shipped…',
        'account_ids'   => [$account->id],
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]);

    $result = calendarTool()->execute(
        ['action' => 'list_month', 'month' => '2026-12'],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeTrue()
        ->and($result->data['posts'])->toHaveCount(0);
});

it('answers with an error for a month it cannot read', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $result = calendarTool()->execute(
        ['action' => 'list_month', 'month' => 'November'],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('month must look like 2026-11');
});

it('answers with an error for an unknown action instead of throwing', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $result = calendarTool()->execute(['action' => 'nonsense'], 1, null, principalContext($principalId));

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Invalid action 'nonsense'");
});

it('carries the total, so a month longer than the list is known', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    postService()->create($principalId, [
        'title'         => 'Q4 release',
        'body'          => 'We shipped…',
        'account_ids'   => [$account->id],
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]);

    $result = calendarTool()->execute(
        ['action' => 'list_month', 'month' => '2026-11'],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->data['total'])->toBe(1)
        ->and($result->data['posts'])->toHaveCount(1);
});
