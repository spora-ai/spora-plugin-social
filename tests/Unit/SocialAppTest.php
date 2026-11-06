<?php

declare(strict_types=1);

use Spora\Plugins\Social\SocialApp;

/**
 * The panel the plugin contributes to the host.
 */
it('names the panel after the plugin', function () {
    $app = new SocialApp();

    expect($app->name())->toBe('social')
        ->and($app->displayName())->toBe('Social')
        ->and($app->description())->toContain('Publish to LinkedIn, Facebook and Instagram')
        ->and($app->icon())->toBe('globe')
        ->and($app->accent())->toBe('sky')
        ->and($app->entry())->toBe('main.js');
});

it('names the aggregator in the description', function () {
    // The aggregator is optional and invisible by default, and a
    // description that does not mention it reads as though the plugin
    // reaches three networks and no more.
    expect((new SocialApp())->description())->toContain('aggregator')
        ->and((new SocialApp())->description())->toContain('does not connect to natively');
});
