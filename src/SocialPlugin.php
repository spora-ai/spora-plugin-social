<?php

declare(strict_types=1);

namespace Spora\Plugins\Social;

use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Social\Http\OAuthCallbackController;
use Spora\Plugins\Social\Http\SocialCalendarController;
use Spora\Plugins\Social\Http\SocialConnectionController;
use Spora\Plugins\Social\Http\SocialMediaController;
use Spora\Plugins\Social\Http\SocialPostController;
use Spora\Plugins\Social\OAuth\OAuthProviderFactory;
use Spora\Plugins\Social\OAuth\OAuthProviderResolver;
use Spora\Plugins\Social\OAuth\SocialStateCodec;
use Spora\Plugins\Social\Publish\FacebookAdapter;
use Spora\Plugins\Social\Publish\InstagramAdapter;
use Spora\Plugins\Social\Publish\LinkedInAdapter;
use Spora\Plugins\Social\Publish\PlatformApi;
use Spora\Plugins\Social\Publish\PlatformHttpClient;
use Spora\Plugins\Social\Publish\PublisherRegistry;
use Spora\Plugins\Social\Scheduling\SocialDueWorkProvider;
use Spora\Plugins\Social\Service\ApiVersions;
use Spora\Plugins\Social\Service\ContainerReconciliation;
use Spora\Plugins\Social\Service\MediaPipeline;
use Spora\Plugins\Social\Service\SocialAppUrlProbe;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Plugins\Social\Service\SocialDraftService;
use Spora\Plugins\Social\Service\SocialMetricsService;
use Spora\Plugins\Social\Service\SocialPostService;
use Spora\Plugins\Social\Service\SocialPublisher;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Settings\SocialSettingsProvider;
use Spora\Plugins\Social\Tools\SocialAnalyticsTool;
use Spora\Plugins\Social\Tools\SocialCalendarTool;
use Spora\Plugins\Social\Tools\SocialConnectionsTool;
use Spora\Plugins\Social\Tools\SocialMediaTool;
use Spora\Plugins\Social\Tools\SocialPostTool;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin entry point for native social publishing.
 *
 * The routes live behind the session middleware and CSRF, except the
 * OAuth callback: the platform's browser arrives with no session, so the
 * signed state is the authentication there.
 */
final class SocialPlugin extends AbstractPlugin implements EventSubscriberInterface
{
    private const AUTH = [AuthMiddleware::class, CsrfMiddleware::class];

    private const BASE = '/api/v1/plugins/social';

    public function getName(): string
    {
        return (new SocialApp())->displayName();
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
            RoutesRegisteringEvent::class => 'onRoutesRegistering',
        ];
    }

    /**
     * Bind the plugin's own services.
     *
     * The classes core already binds are left alone: redefining them
     * would drop their configuration.
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $event->builder()->addDefinitions([
            SocialSettings::class            => \DI\autowire(),
            ApiVersions::class               => \DI\factory(static function (SocialSettings $settings): ApiVersions {
                return new ApiVersions($settings->graphApiVersion(), $settings->linkedInApiVersion());
            }),
            PlatformApi::class              => \DI\factory(static function (
                PlatformHttpClient $http,
                ApiVersions $versions,
            ): PlatformApi {
                return new PlatformApi($http, $versions);
            }),
            SocialStateCodec::class          => \DI\autowire(),
            OAuthProviderFactory::class      => \DI\autowire(),
            OAuthProviderResolver::class     => \DI\autowire(OAuthProviderFactory::class),
            SocialConnectionService::class   => \DI\autowire(),
            SocialAppUrlProbe::class         => \DI\autowire(),
            MediaPipeline::class             => \DI\autowire(),
            PlatformHttpClient::class        => \DI\autowire(),
            LinkedInAdapter::class           => \DI\autowire(),
            FacebookAdapter::class           => \DI\autowire(),
            InstagramAdapter::class          => \DI\autowire(),
            PublisherRegistry::class         => \DI\autowire(),
            SocialPostService::class         => \DI\autowire(),
            SocialPublisher::class            => \DI\autowire(),
            ContainerReconciliation::class    => \DI\autowire(),
            SocialMetricsService::class      => \DI\autowire(),
            SocialDraftService::class        => \DI\autowire(),
            SocialDueWorkProvider::class     => \DI\autowire(),
            SocialSettingsProvider::class    => \DI\autowire(),
            SocialConnectionController::class => \DI\autowire(),
            SocialPostController::class      => \DI\autowire(),
            SocialCalendarController::class  => \DI\autowire(),
            SocialMediaController::class     => \DI\autowire(),
            OAuthCallbackController::class   => \DI\autowire(),
            SocialConnectionsTool::class     => \DI\autowire(),
            SocialPostTool::class            => \DI\autowire(),
            SocialMediaTool::class           => \DI\autowire(),
            SocialAnalyticsTool::class       => \DI\autowire(),
            SocialCalendarTool::class        => \DI\autowire(),
        ]);
    }

    public function onRoutesRegistering(RoutesRegisteringEvent $event): void
    {
        $routes = $event->routes();
        $base = self::BASE;

        $routes->addRoute('GET', $base . '/config', [SocialConnectionController::class, 'config'], self::AUTH);

        $routes->addRoute('GET', $base . '/connections', [SocialConnectionController::class, 'connections'], self::AUTH);
        $routes->addRoute('GET', $base . '/connections/{id}', [SocialConnectionController::class, 'connection'], self::AUTH);
        $routes->addRoute('POST', $base . '/connections/connect', [SocialConnectionController::class, 'connect'], self::AUTH);
        $routes->addRoute('DELETE', $base . '/connections/{id}', [SocialConnectionController::class, 'disconnect'], self::AUTH);
        $routes->addRoute('POST', $base . '/connections/{id}/refresh', [SocialConnectionController::class, 'refresh'], self::AUTH);
        $routes->addRoute('POST', $base . '/connections/{id}/health', [SocialConnectionController::class, 'health'], self::AUTH);

        $routes->addRoute('GET', $base . '/posts', [SocialPostController::class, 'posts'], self::AUTH);
        $routes->addRoute('POST', $base . '/posts', [SocialPostController::class, 'createPost'], self::AUTH);
        $routes->addRoute('GET', $base . '/posts/{id}', [SocialPostController::class, 'post'], self::AUTH);
        $routes->addRoute('PATCH', $base . '/posts/{id}', [SocialPostController::class, 'updatePost'], self::AUTH);
        $routes->addRoute('DELETE', $base . '/posts/{id}', [SocialPostController::class, 'deletePost'], self::AUTH);
        $routes->addRoute('POST', $base . '/posts/{id}/publish', [SocialPostController::class, 'publishPost'], self::AUTH);
        $routes->addRoute('POST', $base . '/posts/{id}/retry', [SocialPostController::class, 'retryPost'], self::AUTH);
        $routes->addRoute('DELETE', $base . '/posts/{id}/published', [SocialPostController::class, 'deletePublished'], self::AUTH);

        $routes->addRoute('GET', $base . '/calendar', [SocialCalendarController::class, 'calendar'], self::AUTH);
        $routes->addRoute('GET', $base . '/media', [SocialMediaController::class, 'media'], self::AUTH);
        $routes->addRoute('POST', $base . '/media/check-fit', [SocialMediaController::class, 'checkFit'], self::AUTH);
        $routes->addRoute('POST', $base . '/media/prepare', [SocialMediaController::class, 'prepareMedia'], self::AUTH);
        $routes->addRoute('POST', $base . '/media/attach', [SocialMediaController::class, 'attachMedia'], self::AUTH);
        $routes->addRoute('POST', $base . '/media/detach', [SocialMediaController::class, 'detachMedia'], self::AUTH);

        $routes->addRoute('POST', $base . '/drafts/generate', [SocialMediaController::class, 'generateDraft'], self::AUTH);

        $routes->addRoute('GET', $base . '/analytics/summary', [SocialPostController::class, 'analyticsSummary'], self::AUTH);
        $routes->addRoute('GET', $base . '/analytics/posts/{postId}', [SocialPostController::class, 'postAnalytics'], self::AUTH);

        // The platform's browser has no session, so this route is not
        // behind the session middleware: the signed state authenticates it.
        $routes->addRoute('GET', $base . '/oauth/{provider}/callback', [OAuthCallbackController::class, 'callback'], []);
    }

    /**
     * @return list<class-string<\Spora\Tools\ToolInterface>>
     */
    public function tools(): array
    {
        return [
            SocialConnectionsTool::class,
            SocialPostTool::class,
            SocialMediaTool::class,
            SocialAnalyticsTool::class,
            SocialCalendarTool::class,
        ];
    }

    /**
     * @return list<class-string<\Spora\Apps\AppInterface>>
     */
    public function apps(): array
    {
        return [SocialApp::class];
    }

    /**
     * The plugin's own due work: the posts whose time has come, the
     * readings that are due, and the publishes that half-finished.
     *
     * @return list<class-string<\Spora\Scheduling\DueWorkProviderInterface>>
     */
    public function dueWorkProviders(): array
    {
        return [SocialDueWorkProvider::class];
    }

    /**
     * The operator settings the plugin contributes.
     *
     * @return list<class-string<\Spora\Settings\PluginSettingsProviderInterface>>
     */
    public function pluginSettingProviders(): array
    {
        return [SocialSettingsProvider::class];
    }

    public function schemaVersion(): int
    {
        return 6;
    }

    /**
     * The agent template the plugin ships: an agent that posts for the
     * operator on a schedule.
     *
     * @return string[]
     */
    public function agentTemplatePaths(): array
    {
        return [
            __DIR__ . '/../agent-templates',
        ];
    }

    /**
     * The skill the plugin ships: the operating manual for the social
     * tools, including the per-platform rules.
     *
     * @return string[]
     */
    public function skillPaths(): array
    {
        return [
            __DIR__ . '/../skills',
        ];
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/../database/migrations';
    }

    /**
     * The plugin's `locales/` directory — one `<locale>.json` per
     * language, keyed by the English source string.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }
}
