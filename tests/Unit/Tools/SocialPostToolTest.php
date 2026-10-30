<?php

declare(strict_types=1);

use Spora\Plugins\Social\Service\SocialPublisher;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;
use Spora\Plugins\Social\Tools\SocialPostTool;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * The post tool the tests publish with.
 */
function postTool(?SocialPublisher $publisher = null): SocialPostTool
{
    return new SocialPostTool(postService(), $publisher ?? postPublisher());
}

/**
 * A draft the tool created.
 */
function createDraft(SocialPostTool $tool, int $principalId, int $accountId): ToolResult
{
    return $tool->execute([
        'action'      => 'create',
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$accountId],
    ], 1, null, principalContext($principalId));
}

it('creates a draft', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $tool = postTool();

    $result = $tool->execute([
        'action'      => 'create',
        'title'       => SocialFixtures::POST_TITLE,
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['status'])->toBe('draft')
        ->and($result->data['post']['title'])->toBe(SocialFixtures::POST_TITLE)
        ->and($result->data['post']['targets'])->toHaveCount(1);
});

it('schedules a post', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $tool = postTool();

    $result = $tool->execute([
        'action'        => 'schedule',
        'body'          => SocialFixtures::POST_BODY,
        'account_ids'   => [$account->id],
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['status'])->toBe('scheduled')
        ->and($result->data['post']['scheduled_for'])->toStartWith('2026-11-02');
});

it('publishes a post and reports the per-account outcome', function () {
    $service = postPublisher(['linkedin' => [['body' => '{"id":"urn:li:share:9"}']]]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $tool = postTool($service);

    $created = createDraft($tool, $principalId, $account->id);

    $postId = $created->data['post']['id'];

    $result = $tool->execute(['action' => 'publish', 'post_id' => $postId], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['status'])->toBe('published')
        ->and($result->data['post']['targets'][0]['external_id'])->toBe('urn:li:share:9');
});

it('takes a published post back down', function () {
    $service = postPublisher([
        'linkedin' => [
            ['body' => '{"id":"urn:li:share:9"}'],
            ['body' => '', 'status' => 204],
        ],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $tool = postTool($service);

    $created = createDraft($tool, $principalId, $account->id);

    $postId = $created->data['post']['id'];

    $tool->execute(['action' => 'publish', 'post_id' => $postId], 1, null, principalContext($principalId));

    $result = $tool->execute(['action' => 'delete', 'post_id' => $postId], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['status'])->toBe('cancelled')
        ->and($result->data['post']['targets'][0]['status'])->toBe('deleted');
});

it('lists the posts of the principal', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $tool = postTool();

    $tool->execute([
        'action'      => 'create',
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
    ], 1, null, principalContext($principalId));

    $result = $tool->execute(['action' => 'list'], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['total'])->toBe(1)
        ->and($result->data['items'])->toHaveCount(1);
});

it('inspects one post', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $tool = postTool();

    $created = createDraft($tool, $principalId, $account->id);

    $result = $tool->execute(
        ['action' => 'inspect', 'post_id' => $created->data['post']['id']],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['body'])->toBe(SocialFixtures::POST_BODY);
});

it('updates a post', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $tool = postTool();

    $created = createDraft($tool, $principalId, $account->id);

    $result = $tool->execute([
        'action'  => 'update',
        'post_id' => $created->data['post']['id'],
        'title'   => 'A better title',
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['title'])->toBe('A better title');
});

it('retries the failed targets of a post', function () {
    $service = postPublisher([
        'linkedin' => [['body' => '{"message":"Invalid author"}', 'status' => 422]],
        'facebook' => [['body' => '{"error":{"message":"Missing permission"}}', 'status' => 400]],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');
    $facebook = publishableAccount($principalId, 'facebook', 'page');

    $tool = postTool($service);

    $created = $tool->execute([
        'action'      => 'create',
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$linkedIn->id, $facebook->id],
    ], 1, null, principalContext($principalId));

    $postId = $created->data['post']['id'];

    $tool->execute(['action' => 'publish', 'post_id' => $postId], 1, null, principalContext($principalId));

    $result = $tool->execute(['action' => 'retry', 'post_id' => $postId], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['status'])->toBe('failed');
});

it('answers with an error when a post id is missing', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $tool = postTool();

    $result = $tool->execute(['action' => 'publish'], 1, null, principalContext($principalId));

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('post_id is required');
});

it('answers with an error for an unknown action instead of throwing', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $tool = postTool();

    $result = $tool->execute(['action' => 'nonsense'], 1, null, principalContext($principalId));

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Invalid action 'nonsense'");
});

it('marks the posts it writes as written by an agent', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $tool = postTool();

    $result = $tool->execute([
        'action'      => 'create',
        'title'       => SocialFixtures::POST_TITLE,
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['source'])->toBe('ai');
});

it('filters the list by the account the post goes to', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');
    $facebook = publishableAccount($principalId, 'facebook', 'page');

    $tool = postTool();

    $tool->execute([
        'action'      => 'create',
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$linkedIn->id],
    ], 1, null, principalContext($principalId));

    $tool->execute([
        'action'      => 'create',
        'body'        => 'We shipped again…',
        'account_ids' => [$facebook->id],
    ], 1, null, principalContext($principalId));

    $result = $tool->execute(
        ['action' => 'list', 'account_id' => $linkedIn->id],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeTrue()
        ->and($result->data['total'])->toBe(1)
        ->and($result->data['items'][0]['body'])->toBe(SocialFixtures::POST_BODY);
});

it('names the action the operator is about to confirm', function () {
    $tool = new SocialPostTool(postService(), postPublisher());

    expect($tool->describeAction(['action' => 'publish', 'post_id' => 7]))
        ->toBe('Social post publish: #7')
        ->and($tool->describeAction(['action' => 'schedule', 'scheduled_for' => SocialFixtures::CALENDAR_MOMENT]))
        ->toBe('Social post schedule: ' . SocialFixtures::CALENDAR_MOMENT)
        ->and($tool->describeAction(['action' => 'list']))
        ->toBe('Social post list');
});

it('attaches the media assets the agent names', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/jpeg', 'payload' => jpegBytes()]);

    $tool = new SocialPostTool(postService(), postPublisher());

    $result = $tool->execute([
        'action'         => 'create',
        'body'           => SocialFixtures::POST_BODY,
        'account_ids'    => [$account->id],
        'media_asset_ids' => [$asset->id],
    ], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['post']['media'])->toHaveCount(1);
});
