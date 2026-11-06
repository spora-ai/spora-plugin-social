<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Http;

use Spora\Auth\AuthService;
use Spora\Plugins\Social\Aggregator\AggregatorProvider;
use Spora\Plugins\Social\Aggregator\AggregatorService;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Service\PlatformFit;
use Spora\Plugins\Social\Service\SocialAppUrlProbe;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The connections: one login per provider, and the accounts it covers.
 *
 * The operator connects a provider, not a network: Meta's one login
 * covers the Instagram professional account and the Facebook Page.
 */
final class SocialConnectionController extends AbstractSocialController
{
    public function __construct(
        AuthService $auth,
        PrincipalResolver $principals,
        PrincipalService $principalService,
        private readonly SocialConnectionService $connections,
        private readonly SocialSettings $settings,
        private readonly SocialAppUrlProbe $appUrlProbe,
        private readonly AggregatorService $aggregators,
    ) {
        parent::__construct($auth, $principals, $principalService);
    }

    /**
     * What the operator has configured, and what the platforms accept.
     *
     * The frontend uses the entries to disable a connect button that cannot
     * work, and to say why — the operator prerequisite is a setting, not a
     * mystery.
     *
     * **The redirect URL travels with them.** The accounts screen shows it so
     * the operator can copy it into the platform's own form; it is the one
     * value they cannot work out, because it is built from the address this
     * instance is reached at.
     *
     * The rules travel with them: the composer's counters and the badge on
     * an attachment read the limits from here, because the backend is what
     * refuses the publish. A copy in the frontend warns about a number that
     * is no longer enforced.
     */
    public function config(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $providers = [];

            foreach (SocialProvider::cases() as $provider) {
                $providers[$provider->value] = [
                    'configured' => $this->settings->isConfigured($provider),
                    'client_id'  => $this->settings->clientId($provider),
                    'config_id'  => $provider === SocialProvider::Meta ? $this->settings->metaConfigId() : null,
                    'needs_setup' => $this->setupNotice($provider),
                    'redirect_uri' => $this->redirectUri($provider),
                ];
            }

            return $this->ok([
                'providers'      => $providers,
                'aggregators'    => $this->aggregators($request),
                'app_url'        => $this->settings->appUrl(),
                'app_url_public' => $this->appUrlProbe->isPubliclyReachable($this->settings->appUrl()),
                'rules'          => PlatformFit::apiRules(),
            ]);
        });
    }

    /**
     * The aggregators the plugin can publish through.
     *
     * An aggregator is not an OAuth provider — it has no client id, no
     * redirect URI and no setup — so it gets its own section rather
     * than a corner of the providers' one.
     *
     * **The key is the principal's**, so the flag is the principal's too:
     * the request names one, and without one the answer is that nobody's
     * key is known.
     *
     * @return array<string, array{configured: bool, label: string, needs_setup: null}>
     */
    private function aggregators(Request $request): array
    {
        $principalId = $this->optionalPrincipalId($request);

        $aggregators = [];

        foreach (AggregatorProvider::cases() as $provider) {
            $aggregators[$provider->value] = [
                'configured'  => $principalId !== null && $this->aggregators->isConfigured($principalId, $provider),
                'label'       => $provider->label(),
                'needs_setup' => null,
            ];
        }

        return $aggregators;
    }

    /**
     * The principal a read is scoped to, when the request names one.
     */
    private function optionalPrincipalId(Request $request): ?int
    {
        $requested = $this->requestedPrincipalId($request);

        if ($requested === null) {
            return null;
        }

        if (!in_array($requested, $this->principals->visiblePrincipalIds($this->userId()), true)) {
            throw SocialException::forbidden('You cannot act for this principal.');
        }

        return $requested;
    }

    /**
     * The card the accounts screen shows for a provider that is not set
     * up.
     *
     * Null when the provider needs no setup: a notice is a notice
     * *because* something is missing, and one that is always there says
     * nothing. The wording is the plugin's own — it is about the plugin's
     * setup, and the host translates it.
     *
     * @return array{title: string, body: string}|null
     */
    private function setupNotice(SocialProvider $provider): ?array
    {
        if (!$this->settings->needsSetup($provider)) {
            return null;
        }

        return match ($provider) {
            SocialProvider::Meta => [
                'title' => 'Meta integration needs setup',
                'body'  => 'Add the app id, the app secret and the configuration id in Settings → Plugins.',
            ],
            SocialProvider::LinkedIn => [
                'title' => 'LinkedIn integration needs setup',
                'body'  => 'Add the client id, the client secret and the requested permissions in Settings → Plugins.',
            ],
        };
    }

    /**
     * The URL the platform has to be given, when there is one to give.
     *
     * The accounts screen shows it so the operator can copy it into the
     * platform's own form. It is the one value they cannot work out: it is
     * built from the address this instance is reached at, and the frontend
     * does not know that address.
     *
     * A URL that cannot be registered is not named. A local address without
     * the development switch is refused by every platform, so naming it would
     * send the operator to register something that cannot work — the screen
     * says there is none instead, the way the setting's own description does.
     */
    private function redirectUri(SocialProvider $provider): ?string
    {
        if (!$this->settings->canRegisterTheRedirectUri()) {
            return null;
        }

        return $this->settings->oauthRedirectUri($provider);
    }

    /**
     * The connections of a principal, each with the accounts it covers.
     */
    public function connections(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);

            $connections = array_map(
                static fn($connection): array => SocialConnectionResource::toArray($connection),
                $this->connections->listForPrincipal($principalId),
            );

            return $this->ok($connections);
        });
    }

    public function connection(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);
            $connection = $this->connections->find($this->id($request, 'id'), $principalId);

            return $this->ok(SocialConnectionResource::toArray($connection));
        });
    }

    /**
     * Start the login round trip.
     *
     * The provider decides the dialog: Meta's is the Facebook Login for
     * Business one, and it carries the configuration id rather than a
     * scope list.
     */
    public function connect(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $body = $this->body($request);

            $provider = SocialProvider::tryFromName((string) ($body['provider'] ?? ''));
            if ($provider === null) {
                throw SocialException::validation(
                    'provider must be meta or linkedin.',
                );
            }

            return $this->ok($this->connections->startConnect($principalId, $provider));
        });
    }

    public function disconnect(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $this->connections->disconnect($this->id($request, 'id'), $principalId);

            return $this->ok(['deleted' => true]);
        });
    }

    /**
     * Renew the connection's token.
     *
     * A Meta token has no refresh grant, so this says so rather than
     * pretending to have renewed anything.
     */
    public function refresh(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $connection = $this->connections->refresh($this->id($request, 'id'), $principalId);

            return $this->ok(SocialConnectionResource::toArray($connection));
        });
    }

    /**
     * Ask the platform whether the connection still works.
     */
    public function health(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);

            return $this->ok($this->connections->health($this->id($request, 'id'), $principalId));
        });
    }
}
