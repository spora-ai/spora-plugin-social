<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tools;

use Spora\Plugins\Social\Service\SocialMetricsService;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * The numbers.
 *
 * The networks stay apart: each counts reach its own way, so a combined
 * figure is labelled a rough one and the rows are kept separate. A
 * counter the platform does not report stays empty rather than becoming a
 * zero that reads as "nobody saw it".
 */
#[Tool(
    name: 'social_analytics',
    description: 'Read how the posts did. A post\'s numbers, a period\'s totals, the top posts, or a per-network comparison. Every reading is a dated snapshot — the platforms keep revising impressions for days.',
    displayName: 'Social Analytics',
    category: 'social',
    icon: 'globe',
    recommendsSkills: ['social-poster'],
)]
#[ToolOperation(
    name: 'post_metrics',
    description: 'The numbers of one post, per account it went to.',
    operatorDescription: 'Read one post\'s numbers',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'period_totals',
    description: 'The totals of the last N days, per network. The combined figure is a rough one: the three platforms count reach differently.',
    operatorDescription: 'Read the totals of a period',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'top_posts',
    description: 'The posts of the last N days that reached the most people.',
    operatorDescription: 'Read the top posts of a period',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'network_comparison',
    description: 'The same period, one row per network, so the platforms can be compared without being added up.',
    operatorDescription: 'Compare the networks',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(name: 'post_id', type: 'integer', description: 'The post id. Required for post_metrics.', required: ['post_metrics'])]
#[ToolParameter(name: 'days', type: 'integer', description: 'How many days back to look (default 30, capped at 365).', required: false, default: 30)]
final class SocialAnalyticsTool extends AbstractSocialTool
{
    public function __construct(
        private readonly SocialMetricsService $metrics,
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        try {
            $principalId = $this->principalId($agentId, $context);
            $operation = (string) ($arguments['action'] ?? 'period_totals');
            $days = max(1, min(365, (int) ($arguments['days'] ?? 30)));

            return match ($operation) {
                'post_metrics' => $this->postMetrics($principalId, $arguments),
                'period_totals' => $this->periodTotals($principalId, $days),
                'top_posts' => $this->topPosts($principalId, $days),
                'network_comparison' => $this->networkComparison($principalId, $days),
                default => new ToolResult(false, "Invalid action '{$operation}'. Use post_metrics, period_totals, top_posts or network_comparison."),
            };
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? 'period_totals');

        if ($operation === 'post_metrics') {
            return 'Social analytics: post #' . (int) ($arguments['post_id'] ?? 0);
        }

        return 'Social analytics: ' . $operation;
    }

    private function postMetrics(int $principalId, array $arguments): ToolResult
    {
        $postId = (int) ($arguments['post_id'] ?? 0);

        if ($postId <= 0) {
            return new ToolResult(false, 'Error (VALIDATION_ERROR): post_id is required.');
        }

        $analytics = $this->metrics->postAnalytics($postId, $principalId);

        return ToolResult::ok(
            'The numbers of post #' . $postId . '.',
            $analytics,
        );
    }

    private function periodTotals(int $principalId, int $days): ToolResult
    {
        $summary = $this->metrics->summary($principalId, $days);

        return ToolResult::ok(
            'The totals of the last ' . $days . ' days. The combined figure is a rough one — the platforms count reach differently.',
            ['totals' => $summary['totals'], 'notes' => $summary['notes']],
        );
    }

    private function topPosts(int $principalId, int $days): ToolResult
    {
        $summary = $this->metrics->summary($principalId, $days);

        return ToolResult::ok(
            'The top posts of the last ' . $days . ' days.',
            ['top_posts' => $summary['top_posts']],
        );
    }

    private function networkComparison(int $principalId, int $days): ToolResult
    {
        $summary = $this->metrics->summary($principalId, $days);

        return ToolResult::ok(
            'One row per network for the last ' . $days . ' days.',
            ['by_network' => $summary['by_network'], 'series' => $summary['series'], 'notes' => $summary['notes']],
        );
    }
}
