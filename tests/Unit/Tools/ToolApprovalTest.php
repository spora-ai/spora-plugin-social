<?php

declare(strict_types=1);

use Spora\Plugins\Social\Tools\SocialAnalyticsTool;
use Spora\Plugins\Social\Tools\SocialCalendarTool;
use Spora\Plugins\Social\Tools\SocialConnectionsTool;
use Spora\Plugins\Social\Tools\SocialMediaTool;
use Spora\Plugins\Social\Tools\SocialPostTool;

it('asks the operator before anything that reaches a network', function () {
    $post = operationsOf(SocialPostTool::class);

    // A draft commits nothing, so it needs no approval. Everything that
    // puts the copy on a network does — including a schedule, which
    // publishes later without asking again, and a delete, which takes it
    // back down again.
    expect($post['create']->requiresApprovalByDefault)->toBeFalse()
        ->and($post['schedule']->requiresApprovalByDefault)->toBeTrue()
        ->and($post['publish']->requiresApprovalByDefault)->toBeTrue()
        ->and($post['update']->requiresApprovalByDefault)->toBeTrue()
        ->and($post['retry']->requiresApprovalByDefault)->toBeTrue()
        ->and($post['delete']->requiresApprovalByDefault)->toBeTrue()
        ->and($post['list']->requiresApprovalByDefault)->toBeFalse()
        ->and($post['inspect']->requiresApprovalByDefault)->toBeFalse();
});

it('asks the operator before a media change reaches a network', function () {
    $media = operationsOf(SocialMediaTool::class);

    expect($media['attach']->requiresApprovalByDefault)->toBeTrue()
        ->and($media['detach']->requiresApprovalByDefault)->toBeTrue()
        ->and($media['list_assets']->requiresApprovalByDefault)->toBeFalse()
        ->and($media['check_fit']->requiresApprovalByDefault)->toBeFalse()
        ->and($media['prepare']->requiresApprovalByDefault)->toBeFalse();
});

it('never asks the operator twice for a read', function () {
    foreach ([SocialConnectionsTool::class, SocialAnalyticsTool::class, SocialCalendarTool::class] as $class) {
        foreach (operationsOf($class) as $name => $operation) {
            if (in_array($name, ['list', 'health', 'inspect', 'list_assets', 'check_fit', 'prepare', 'list_month', 'post_metrics', 'period_totals', 'top_posts', 'network_comparison'], true)) {
                expect($operation->requiresApprovalByDefault)->toBeFalse();
            }
        }
    }
});
