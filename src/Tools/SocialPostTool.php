<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tools;

use Spora\Plugins\Social\Http\SocialPostResource;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Service\SocialPostService;
use Spora\Plugins\Social\Service\SocialPublisher;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * The posts: write one, schedule it, publish it, look at what happened.
 *
 * This is the agent-driven mode's whole path: the agent writes the copy,
 * the operator approves it, and the worker publishes it when the moment
 * comes.
 *
 * The approval rules follow what each operation commits. A draft commits
 * nothing, so it needs none. A scheduled post commits the copy to the
 * networks later without asking again, so it needs one — the same rule
 * the house applies to CalDAV's `create_event` and the mail plugin's
 * `send_email`.
 */
#[Tool(
    name: 'social_post',
    description: 'Write and publish social posts. Create a draft, schedule it for a moment, publish it now, read the results, or retry the accounts that failed. One body per platform: the copy that reads well on LinkedIn is not the copy that reads well on Instagram. The rules the backend enforces at publish time: LinkedIn takes an image or no media (JPEG, PNG or GIF, 8 MB) and a body up to 3,000 characters; Facebook takes JPEG, PNG, GIF, BMP or MP4 up to 1 GB and a body up to 63,206 characters; Instagram REQUIRES media — a JPEG or an MP4 up to 100 MB, no PNG — and a caption up to 2,200 characters. A body over the limit is refused. A body per platform goes in platform_bodies.',
    displayName: 'Social Post',
    category: 'social',
    icon: 'globe',
    recommendsSkills: ['social-poster'],
)]
#[ToolOperation(
    name: 'create',
    description: 'Create a draft post for this principal. Give it a body, and optionally a body per platform and the media assets to attach. A draft goes nowhere until it is scheduled or published, so this needs no approval.',
    operatorDescription: 'Create a social post draft',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'schedule',
    description: 'Create a post that goes out at a given moment — this is the one call that schedules it, no separate create first. The post is published by the worker when the time comes, without asking again, so the operator approves the copy and the time now.',
    operatorDescription: 'Schedule a social post',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'publish',
    description: 'Publish a post to its accounts right now. This cannot be undone on the networks.',
    operatorDescription: 'Publish a social post',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'list',
    description: 'List the posts of this principal, newest first. Filter by status, platform, account, or by the window of days the post is due or went out in — use that to see what is already going out and not post it twice. At most 100 posts at a time; the result carries the total, so a longer list is known.',
    operatorDescription: 'List social posts',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'inspect',
    description: 'Read one post with the per-account outcome: what published, what failed and why.',
    operatorDescription: 'Inspect a social post',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'update',
    description: 'Change a post: its title, its body, its per-platform bodies, the accounts it goes to or the media it carries. A post that is already scheduled changes what goes out, so this needs the operator to agree.',
    operatorDescription: 'Update a social post',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'retry',
    description: 'Publish again only the accounts that failed. The accounts that already published are left alone.',
    operatorDescription: 'Retry the failed parts of a post',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'delete',
    description: 'Take a published post back down. Each account that published is deleted on its own network. '
        . 'LinkedIn and Facebook can delete a published post; Instagram cannot — its API has no delete for published '
        . 'media, so that target says so and stays published.',
    operatorDescription: 'Take a published post back down',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolParameter(name: 'post_id', type: 'integer', description: 'The post id. Required for publish, inspect, update, retry and delete.', required: ['publish', 'inspect', 'update', 'retry', 'delete'])]
#[ToolParameter(name: 'title', type: 'string', description: 'An internal label for the post. It is never published.', required: false)]
#[ToolParameter(name: 'body', type: 'string', description: 'The post text. Give one body per platform where the platforms differ — put the platform-specific texts in platform_bodies and use this for the rest.', required: ['create', 'schedule', 'update'])]
#[ToolParameter(name: 'platform_bodies', type: 'object', description: 'A body per platform, keyed by platform name: linkedin, facebook, instagram. A platform without its own body uses `body`.', required: false)]
#[ToolParameter(name: 'account_ids', type: 'array', description: 'The ids of the connected accounts the post goes to. Use `social_connections` with action `list` to find them.', required: ['create', 'schedule'], items: ['type' => 'integer'])]
#[ToolParameter(name: 'media_asset_ids', type: 'array', description: 'The Media Archive assets to attach. Instagram cannot publish without one, so an Instagram post needs an attachment.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'scheduled_for', type: 'string', description: 'When the post should go out, as an ISO 8601 timestamp. Required by schedule.', required: ['schedule'])]
#[ToolParameter(name: 'timezone', type: 'string', description: 'The timezone the schedule is written in, e.g. Europe/Zurich.', required: false)]
#[ToolParameter(name: 'status', type: 'string', description: 'Filter the list by status: draft, scheduled, publishing, published, partial, failed or cancelled.', required: false)]
#[ToolParameter(name: 'platform', type: 'string', description: 'Filter the list by platform: linkedin, facebook or instagram.', required: false)]
#[ToolParameter(name: 'account_id', type: 'integer', description: 'Filter the list by the connected account the post goes to.', required: false)]
#[ToolParameter(name: 'from', type: 'string', description: 'Only the posts due or published from this day on, as YYYY-MM-DD.', required: false)]
#[ToolParameter(name: 'to', type: 'string', description: 'Only the posts due or published up to this day, as YYYY-MM-DD.', required: false)]
#[ToolParameter(name: 'limit', type: 'integer', description: 'How many posts to return (default 20, capped at 100).', required: false, default: 20)]
final class SocialPostTool extends AbstractSocialTool
{
    public function __construct(
        private readonly SocialPostService $posts,
        private readonly SocialPublisher $publisher,
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        try {
            $principalId = $this->principalId($agentId, $context);
            $operation = (string) ($arguments['action'] ?? 'list');

            return match ($operation) {
                'create' => $this->create($principalId, $arguments),
                'schedule' => $this->schedule($principalId, $arguments),
                'publish' => $this->publish($principalId, $arguments),
                'list' => $this->list($principalId, $arguments),
                'inspect' => $this->inspect($principalId, $arguments),
                'update' => $this->update($principalId, $arguments),
                'retry' => $this->retry($principalId, $arguments),
                'delete' => $this->delete($principalId, $arguments),
                default => new ToolResult(false, "Invalid action '{$operation}'. Use create, schedule, publish, list, inspect, update, retry or delete."),
            };
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? 'list');
        $postId = (int) ($arguments['post_id'] ?? 0);

        if (in_array($operation, ['publish', 'retry', 'inspect', 'update', 'delete'], true) && $postId > 0) {
            return 'Social post ' . $operation . ': #' . $postId;
        }

        if ($operation === 'schedule') {
            return 'Social post schedule: ' . (string) ($arguments['scheduled_for'] ?? '(no time)');
        }

        return 'Social post ' . $operation;
    }

    private function create(int $principalId, array $arguments): ToolResult
    {
        $post = $this->posts->create($principalId, $this->input($arguments));

        return ToolResult::ok(
            'Created post #' . $post->id . ' as a draft.',
            ['post' => SocialPostResource::toArray($post)],
        );
    }

    private function schedule(int $principalId, array $arguments): ToolResult
    {
        $input = $this->input($arguments);
        $input['scheduled_for'] = $arguments['scheduled_for'] ?? null;

        $post = $this->posts->create($principalId, $input);

        return ToolResult::ok(
            'Scheduled post #' . $post->id . ' for ' . (string) $post->scheduled_for?->toIso8601String() . '.',
            ['post' => SocialPostResource::toArray($post)],
        );
    }

    private function publish(int $principalId, array $arguments): ToolResult
    {
        $post = $this->publisher->publish($this->postId($arguments), $principalId);

        return ToolResult::ok(
            'Published post #' . $post->id . '. Its status is ' . $post->status . '.',
            ['post' => SocialPostResource::toArray($post)],
        );
    }

    private function list(int $principalId, array $arguments): ToolResult
    {
        $result = $this->posts->listForPrincipal($principalId, [
            'status'     => $arguments['status'] ?? null,
            'platform'   => $arguments['platform'] ?? null,
            'account_id' => $arguments['account_id'] ?? null,
            'from'       => $arguments['from'] ?? null,
            'to'         => $arguments['to'] ?? null,
            'limit'      => $arguments['limit'] ?? null,
        ]);

        $items = array_map(
            static fn(SocialPost $post): array => SocialPostResource::toArray($post),
            $result['items'],
        );

        return ToolResult::ok(
            $result['total'] . ' post(s) for this principal.',
            ['items' => $items, 'total' => $result['total']],
        );
    }

    private function inspect(int $principalId, array $arguments): ToolResult
    {
        $post = $this->posts->find($this->postId($arguments), $principalId);

        return ToolResult::ok(
            'Post #' . $post->id . ' is ' . $post->status . '.',
            ['post' => SocialPostResource::toArray($post)],
        );
    }

    private function update(int $principalId, array $arguments): ToolResult
    {
        $post = $this->posts->update($this->postId($arguments), $principalId, $this->input($arguments));

        return ToolResult::ok(
            'Updated post #' . $post->id . '.',
            ['post' => SocialPostResource::toArray($post)],
        );
    }

    private function retry(int $principalId, array $arguments): ToolResult
    {
        $post = $this->publisher->retry($this->postId($arguments), $principalId);

        return ToolResult::ok(
            'Retried the failed accounts of post #' . $post->id . '. Its status is ' . $post->status . '.',
            ['post' => SocialPostResource::toArray($post)],
        );
    }

    private function delete(int $principalId, array $arguments): ToolResult
    {
        $post = $this->publisher->delete($this->postId($arguments), $principalId);

        return ToolResult::ok(
            'Took down post #' . $post->id . '. Its status is ' . $post->status . '.',
            ['post' => SocialPostResource::toArray($post)],
        );
    }

    /**
     * The post as the service takes it.
     *
     * The source is the agent: a post an agent wrote is not the same
     * thing as one the operator typed, and the source is what tells them
     * apart.
     *
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function input(array $arguments): array
    {
        $input = ['source' => SocialPost::SOURCE_AI];

        foreach (['title', 'body', 'platform_bodies', 'timezone', 'scheduled_for'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $input[$key] = $arguments[$key];
            }
        }

        if (isset($arguments['account_ids'])) {
            $input['account_ids'] = $this->intList($arguments['account_ids']);
        }

        // Media Archive assets are addressed by UUID, not by number.
        if (isset($arguments['media_asset_ids']) && is_array($arguments['media_asset_ids'])) {
            $media = [];
            foreach ($arguments['media_asset_ids'] as $value) {
                if (is_string($value) && $value !== '') {
                    $media[] = ['media_asset_id' => $value, 'position' => count($media)];
                }
            }
            $input['media'] = $media;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function postId(array $arguments): int
    {
        $postId = (int) ($arguments['post_id'] ?? 0);

        if ($postId <= 0) {
            throw \Spora\Plugins\Social\Exception\SocialException::validation('post_id is required.');
        }

        return $postId;
    }
}
