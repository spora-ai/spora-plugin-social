<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Http;

use Spora\Auth\AuthService;
use Spora\Plugins\Social\Aggregator\AggregatorAccountSync;
use Spora\Plugins\Social\Aggregator\AggregatorProvider;
use Spora\Plugins\Social\Aggregator\AggregatorService;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The aggregators: the networks Spora reaches through somebody else's
 * API.
 *
 * An aggregator is not an OAuth provider — there is no login and no
 * refresh grant — so it has its own routes rather than a corner of the
 * OAuth ones. The key is written here and nowhere else: the agent's
 * tools have no way to write a credential.
 */
final class SocialAggregatorController extends AbstractSocialController
{
    /**
     * The aggregator's own service.
     */
    public function __construct(
        AuthService $auth,
        PrincipalResolver $principals,
        PrincipalService $principalService,
        private readonly AggregatorService $aggregators,
        private readonly AggregatorAccountSync $sync,
    ) {
        parent::__construct($auth, $principals, $principalService);
    }

    /**
     * Store the API key the operator pasted.
     */
    public function setAggregator(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $body = $this->body($request);

            $provider = AggregatorProvider::tryFromName((string) ($body['aggregator'] ?? ''));

            if ($provider === null) {
                throw SocialException::validation('aggregator must be zernio.');
            }

            $connection = $this->aggregators->setKey(
                $principalId,
                $provider,
                (string) ($body['api_key'] ?? ''),
            );

            return $this->ok([
                'aggregator'     => $provider->value,
                'label'          => $provider->label(),
                'configured'     => true,
                'connection_id'  => (int) $connection->id,
            ]);
        });
    }

    /**
     * Forget the key.
     *
     * The accounts stay in the aggregator: they are its accounts, and the
     * operator removes them there. The message says so, because a key
     * that stops working looks like a broken setup rather than a
     * deliberate one.
     */
    public function forgetAggregator(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $provider = $this->providerOf($request);

            $this->aggregators->forgetKey($principalId, $provider);

            return $this->ok([
                'aggregator' => $provider->value,
                'label'      => $provider->label(),
                'configured' => false,
                'deleted'    => true,
                'message'    => sprintf(
                    'The %s key is forgotten. The accounts stay in %s — remove them there if you do not want them published to any more.',
                    $provider->label(),
                    $provider->label(),
                ),
            ]);
        });
    }

    /**
     * The aggregator's accounts, read again.
     *
     * The Accounts screen's *"Sync now"* button. Read-only towards the
     * aggregator: the operator creates, renames, moves and disconnects
     * there, and this only learns what is there.
     */
    public function syncAggregator(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $provider = $this->providerOf($request);

            $connection = $this->aggregators->connection($principalId, $provider);

            if ($connection === null) {
                throw SocialException::conflict(sprintf('There is no %s connection to sync.', $provider->label()));
            }

            $result = $this->sync->read($connection);
            $this->sync->apply($connection, $result);

            return $this->ok([
                'aggregator'   => $provider->value,
                'accounts'     => count($result->accounts),
                'disconnected' => count($result->gone),
                'analytics'    => $result->analyticsAvailable,
            ]);
        });
    }

    /**
     * The aggregator a route names.
     */
    private function providerOf(Request $request): AggregatorProvider
    {
        $slug = (string) $request->attributes->get('slug');
        $provider = AggregatorProvider::tryFromName($slug);

        if ($provider === null) {
            throw SocialException::notFound(sprintf('There is no aggregator called %s.', $slug));
        }

        return $provider;
    }
}
