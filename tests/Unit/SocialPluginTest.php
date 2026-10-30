<?php

declare(strict_types=1);

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteParser\Std;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\Social\Http\OAuthCallbackController;
use Spora\Plugins\Social\Scheduling\SocialDueWorkProvider;
use Spora\Plugins\Social\Service\ApiVersions;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Settings\SocialSettingsProvider;
use Spora\Plugins\Social\SocialApp;
use Spora\Plugins\Social\SocialPlugin;
use Spora\Plugins\Social\Tools\SocialAnalyticsTool;
use Spora\Plugins\Social\Tools\SocialCalendarTool;
use Spora\Plugins\Social\Tools\SocialConnectionsTool;
use Spora\Plugins\Social\Tools\SocialMediaTool;
use Spora\Plugins\Social\Tools\SocialPostTool;

/**
 * The routes the plugin registers, with their middleware.
 *
 * FastRoute keeps the static and the variable routes apart, so both are
 * flattened here. A static route is keyed by its path, a variable one by
 * the regex FastRoute compiled it into.
 *
 * @return list<array{method: string, route: string, handler: array{0: class-string, 1: string}, middleware: list<class-string>}>
 */
function registeredRoutes(): array
{
    $collector = new MiddlewareRouteCollector(new Std(), new GroupCountBased());

    (new SocialPlugin())->onRoutesRegistering(new RoutesRegisteringEvent($collector));

    [$static, $variable] = $collector->getData();

    $routes = [];

    foreach ($static as $method => $byPath) {
        foreach ($byPath as $path => $handler) {
            $routes[] = [
                'method'     => $method,
                'route'      => $path,
                'handler'    => $handler['handler'],
                'middleware' => $handler['middleware'],
            ];
        }
    }

    foreach ($variable as $method => $groups) {
        foreach ($groups as $group) {
            foreach ($group['routeMap'] as $variables) {
                $routes[] = [
                    'method'     => $method,
                    'route'      => $group['regex'],
                    'handler'    => $variables[0]['handler'],
                    'middleware' => $variables[0]['middleware'],
                ];
            }
        }
    }

    return $routes;
}

/**
 * The fragment a route pattern leaves in FastRoute's own key.
 *
 * FastRoute compiles every variable route of a method into one regex, so
 * the pattern is matched by the fragment it leaves in that regex.
 */
function routeFragment(string $pattern): string
{
    return (string) preg_replace('/\{[^}]+\}/', '([^/]+)', $pattern);
}

it('registers every route of the api contract under the plugin path', function () {
    $routes = array_column(registeredRoutes(), 'route');

    $expected = [
        '/api/v1/plugins/social/config',
        '/api/v1/plugins/social/connections',
        '/api/v1/plugins/social/connections/{id}',
        '/api/v1/plugins/social/connections/connect',
        '/api/v1/plugins/social/connections/{id}/refresh',
        '/api/v1/plugins/social/connections/{id}/health',
        '/api/v1/plugins/social/posts',
        '/api/v1/plugins/social/posts/{id}',
        '/api/v1/plugins/social/posts/{id}/publish',
        '/api/v1/plugins/social/posts/{id}/retry',
        '/api/v1/plugins/social/calendar',
        '/api/v1/plugins/social/media',
        '/api/v1/plugins/social/media/check-fit',
        '/api/v1/plugins/social/media/prepare',
        '/api/v1/plugins/social/media/attach',
        '/api/v1/plugins/social/media/detach',
        '/api/v1/plugins/social/drafts/generate',
        '/api/v1/plugins/social/analytics/summary',
        '/api/v1/plugins/social/analytics/posts/{postId}',
        '/api/v1/plugins/social/oauth/{platform}/callback',
    ];

    $haystack = implode("\n", $routes);

    foreach ($expected as $pattern) {
        expect($haystack)->toContain(routeFragment($pattern));
    }
});

it('puts every route behind the session and csrf middleware, except the oauth callback', function () {
    foreach (registeredRoutes() as $route) {
        if ($route['handler'][0] === OAuthCallbackController::class) {
            expect($route['middleware'])->toBe([]);

            continue;
        }

        expect($route['middleware'])->toBe([AuthMiddleware::class, CsrfMiddleware::class]);
    }
});

it('serves no slot and no schedule route', function () {
    $routes = implode("\n", array_column(registeredRoutes(), 'route'));

    // The fixed slot is gone, and the agent's own schedules are core's
    // business — they live in the agent's Schedules tab.
    expect($routes)->not->toContain('/plans')
        ->and($routes)->not->toContain('/schedules');
});

it('contributes the five tools', function () {
    expect((new SocialPlugin())->tools())->toBe([
        SocialConnectionsTool::class,
        SocialPostTool::class,
        SocialMediaTool::class,
        SocialAnalyticsTool::class,
        SocialCalendarTool::class,
    ]);
});

it('contributes the app', function () {
    $apps = (new SocialPlugin())->apps();

    expect($apps)->toHaveCount(1)
        ->and((new SocialApp())->name())->toBe('social')
        ->and((new SocialApp())->entry())->toBe('main.js');
});

it('names the due work provider and the settings provider', function () {
    $plugin = new SocialPlugin();

    expect($plugin->dueWorkProviders())->toBe([SocialDueWorkProvider::class])
        ->and($plugin->pluginSettingProviders())->toBe([SocialSettingsProvider::class]);
});

it('points at the migrations and counts them', function () {
    $plugin = new SocialPlugin();
    $path = (string) $plugin->migrationsPath();

    // The version is the count, not a number someone remembers: core skips a
    // component whose stored version is not below the declared one, so a
    // migration added without a bump is silently skipped forever.
    $migrations = glob($path . '/*.php') ?: [];

    expect(realpath($path))->toBe(realpath(dirname(__DIR__, 2) . '/database/migrations'))
        ->and($plugin->schemaVersion())->toBe(count($migrations));
});

it('ships a locale catalogue', function () {
    $paths = (new SocialPlugin())->translationsPaths();

    expect($paths)->toHaveCount(1)
        ->and(is_file($paths[0] . '/de.json'))->toBeTrue();
});

it('declares the manifest slug and class', function () {
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/plugin.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($manifest['slug'])->toBe('social')
        ->and($manifest['class'])->toBe(SocialPlugin::class)
        ->and($manifest['icon'])->toBe('globe');
});

it('binds the plugin services into the container', function () {
    $builder = new DI\ContainerBuilder();

    (new SocialPlugin())->onContainerBuilding(new ContainerBuildingEvent($builder));

    $property = (new ReflectionObject($builder))->getProperty('definitionSources');
    $property->setAccessible(true);

    /** @var list<mixed> $sources */
    $sources = $property->getValue($builder);

    expect($sources)->not->toBeEmpty();
});

it('gives the api versions the version the operator configured', function () {
    $builder = new DI\ContainerBuilder();

    $builder->addDefinitions([
        Spora\Core\Config::class => \DI\factory(static fn(): Spora\Core\Config => new Spora\Core\Config([
            SocialSettings::KEY_GRAPH_API_VERSION    => 'v27.0',
            SocialSettings::KEY_LINKEDIN_API_VERSION => '202701',
        ])),
    ]);

    (new SocialPlugin())->onContainerBuilding(new ContainerBuildingEvent($builder));

    $versions = $builder->build()->get(ApiVersions::class);

    // A version setting that never reaches the endpoints is a setting that
    // does nothing.
    expect($versions->graph)->toBe('v27.0')
        ->and($versions->linkedin)->toBe('202701');
});
