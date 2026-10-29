<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Tools\SocialAnalyticsTool;

it('answers with the totals of a period', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = publishedTarget($principalId, 'linkedin', 'organization', 'urn:li:share:9');

    $row = new Spora\Plugins\Social\Model\SocialPostMetric();
    $row->post_id = $target->post_id;
    $row->target_id = $target->id;
    $row->metric_date = gmdate('Y-m-d');
    $row->reach = 420;
    $row->impressions = 420;
    $row->likes = 20;
    $row->measured_at = Carbon::now();
    $row->save();

    $tool = new SocialAnalyticsTool(metricsService([]));

    $result = $tool->execute(['action' => 'period_totals', 'days' => 30], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['totals']['reach'])->toBe(420)
        ->and($result->data['totals']['likes'])->toBe(20);
});

it('answers with the numbers of one post', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = publishedTarget($principalId, 'linkedin', 'organization', 'urn:li:share:9');

    $tool = new SocialAnalyticsTool(metricsService([]));

    $result = $tool->execute(
        ['action' => 'post_metrics', 'post_id' => $target->post_id],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeTrue()
        ->and($result->data['targets'])->toHaveCount(1)
        ->and($result->data['targets'][0]['platform'])->toBe('linkedin');
});

it('answers with the top posts of a period', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $target = publishedTarget($principalId, 'linkedin', 'organization', 'urn:li:share:9');

    $row = new Spora\Plugins\Social\Model\SocialPostMetric();
    $row->post_id = $target->post_id;
    $row->target_id = $target->id;
    $row->metric_date = gmdate('Y-m-d');
    $row->reach = 900;
    $row->measured_at = Carbon::now();
    $row->save();

    $tool = new SocialAnalyticsTool(metricsService([]));

    $result = $tool->execute(['action' => 'top_posts'], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->data['top_posts'])->toHaveCount(1)
        ->and($result->data['top_posts'][0]['reach'])->toBe(900);
});

it('answers with one row per network', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishedTarget($principalId, 'linkedin', 'organization', 'urn:li:share:9');
    $instagram = publishedTarget($principalId, 'instagram', 'business', '17900000000000002');

    foreach ([[$linkedIn, 420], [$instagram, 500]] as [$target, $reach]) {
        $row = new Spora\Plugins\Social\Model\SocialPostMetric();
        $row->post_id = $target->post_id;
        $row->target_id = $target->id;
        $row->metric_date = gmdate('Y-m-d');
        $row->reach = $reach;
        $row->measured_at = Carbon::now();
        $row->save();
    }

    $tool = new SocialAnalyticsTool(metricsService([]));

    $result = $tool->execute(['action' => 'network_comparison'], 1, null, principalContext($principalId));

    $networks = array_column($result->data['by_network'], 'reach', 'platform');

    expect($result->success)->toBeTrue()
        ->and($networks['linkedin'])->toBe(420)
        ->and($networks['instagram'])->toBe(500);
});

it('answers with an error when a post id is missing', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $tool = new SocialAnalyticsTool(metricsService([]));

    $result = $tool->execute(['action' => 'post_metrics'], 1, null, principalContext($principalId));

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('post_id is required');
});

it('answers with an error for an unknown action instead of throwing', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $tool = new SocialAnalyticsTool(metricsService([]));

    $result = $tool->execute(['action' => 'nonsense'], 1, null, principalContext($principalId));

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Invalid action 'nonsense'");
});
