<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tools;

use Spora\Plugins\Social\Service\SocialPostService;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * What is going out, and when.
 *
 * The agent's answer to "what is already scheduled?" — the question that
 * keeps it from posting the same thing twice.
 *
 * The fixed slot is gone: it published the same words every week, and the
 * platforms ship native scheduled posts now. What is left is the posts
 * themselves — the agent's own drafts and the operator's — with the days
 * they are due.
 */
#[Tool(
    name: 'social_calendar',
    description: 'See what is going out and when. Returns the posts of a month with the day each one is due or went out, so you do not schedule the same thing twice. For a precise window use social_post with action=list and the from/to parameters. At most 100 posts at a time; the result carries the total, so a longer month is known.',
    displayName: 'Social Calendar',
    category: 'social',
    icon: 'globe',
    recommendsSkills: ['social-poster'],
)]
#[ToolOperation(
    name: 'list_month',
    description: 'The posts of a month with their dates — what is scheduled and what already went out. Defaults to the current month; pass the next month as well to see what crosses the month boundary.',
    operatorDescription: 'List the posts of a month',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(name: 'month', type: 'string', description: 'The month to read, as YYYY-MM. Defaults to the current month.', required: false)]
final class SocialCalendarTool extends AbstractSocialTool
{
    public function __construct(
        private readonly SocialPostService $posts,
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        try {
            $principalId = $this->principalId($agentId, $context);
            $operation = (string) ($arguments['action'] ?? 'list_month');

            return match ($operation) {
                'list_month' => $this->listMonth($principalId, $arguments),
                default => new ToolResult(false, "Invalid action '{$operation}'. Use list_month."),
            };
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    public function describeAction(array $arguments): string
    {
        return 'Social calendar: the posts of ' . (string) ($arguments['month'] ?? 'this month');
    }

    private function listMonth(int $principalId, array $arguments): ToolResult
    {
        $month = trim((string) ($arguments['month'] ?? ''));

        if ($month === '') {
            $month = gmdate('Y-m');
        }

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return new ToolResult(false, 'Error (VALIDATION_ERROR): month must look like 2026-11.');
        }

        $result = $this->posts->postsOfMonth($principalId, $month);

        $posts = [];
        foreach ($result['items'] as $post) {
            $posts[] = [
                'post_id'       => (int) $post->id,
                'title'         => $post->title,
                'status'        => (string) $post->status,
                'scheduled_for' => $post->scheduled_for?->toIso8601String(),
                'published_at'  => $post->published_at?->toIso8601String(),
            ];
        }

        return ToolResult::ok(
            count($posts) . ' of ' . $result['total'] . ' post(s) in ' . $month . '.',
            ['month' => $month, 'posts' => $posts, 'total' => $result['total']],
        );
    }
}
