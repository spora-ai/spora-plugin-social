<?php

declare(strict_types=1);

use Spora\Plugins\Social\Tests\Support\SocialFixtures;
use Spora\Plugins\Social\Tools\SocialCalendarTool;

function calendarTool(): SocialCalendarTool
{
    return new SocialCalendarTool(postService());
}

it('lists the posts of a month with their dates', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = postService()->create($principalId, [
        'title'         => SocialFixtures::POST_TITLE,
        'body'          => SocialFixtures::POST_BODY,
        'account_ids'   => [$account->id],
        'scheduled_for' => SocialFixtures::CALENDAR_MOMENT,
    ]);

    $result = calendarTool()->execute(
        ['action' => 'list_month', 'month' => SocialFixtures::CALENDAR_MONTH],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeTrue()
        ->and($result->data['month'])->toBe(SocialFixtures::CALENDAR_MONTH)
        ->and($result->data['posts'])->toHaveCount(1)
        ->and($result->data['posts'][0]['post_id'])->toBe($post->id)
        ->and($result->data['posts'][0]['scheduled_for'])->toStartWith('2026-11-02');
});

it('leaves the posts of another month out', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    postService()->create($principalId, [
        'title'         => SocialFixtures::POST_TITLE,
        'body'          => SocialFixtures::POST_BODY,
        'account_ids'   => [$account->id],
        'scheduled_for' => SocialFixtures::CALENDAR_MOMENT,
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

it('answers with an error for an action the calendar does not have', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $result = calendarTool()->execute(['action' => 'nonsense'], 1, null, principalContext($principalId));

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Invalid action 'nonsense'");
});

it('carries the total, so a month longer than the list is known', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    postService()->create($principalId, [
        'title'         => SocialFixtures::POST_TITLE,
        'body'          => SocialFixtures::POST_BODY,
        'account_ids'   => [$account->id],
        'scheduled_for' => SocialFixtures::CALENDAR_MOMENT,
    ]);

    $result = calendarTool()->execute(
        ['action' => 'list_month', 'month' => SocialFixtures::CALENDAR_MONTH],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->data['total'])->toBe(1)
        ->and($result->data['posts'])->toHaveCount(1);
});

it('names the month it is about to show', function () {
    expect(calendarTool()->describeAction(['month' => SocialFixtures::CALENDAR_MONTH]))
        ->toBe('Social calendar: the posts of ' . SocialFixtures::CALENDAR_MONTH)
        ->and(calendarTool()->describeAction([]))
        ->toBe('Social calendar: the posts of this month');
});
