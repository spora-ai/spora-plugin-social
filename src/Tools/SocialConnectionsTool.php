<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tools;

use Spora\Plugins\Social\Aggregator\AggregatorAccountSync;
use Spora\Plugins\Social\Aggregator\AggregatorProvider;
use Spora\Plugins\Social\Aggregator\AggregatorService;
use Spora\Plugins\Social\Http\SocialConnectionResource;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * The connections: what is connected, how to connect more, and whether a
 * token is still alive.
 *
 * A connection is one login. Meta's covers the Instagram professional
 * account and the Facebook Page together, so the operator connects a
 * provider once and publishes to both.
 *
 * `connect` returns the URL the operator's browser has to visit. The agent
 * cannot visit it — the platform needs a human — so the URL is handed back
 * for the operator to open.
 */
#[Tool(
    name: 'social_connections',
    description: 'Manage the social connections this principal publishes through. List the connections with the accounts each one covers, get the URL to connect a new one, disconnect one, renew a token, or check whether a token still works.',
    displayName: 'Social Connections',
    category: 'social',
    icon: 'globe',
    recommendsSkills: ['social-poster'],
)]
#[ToolOperation(
    name: 'list',
    description: 'List the connected social accounts with their platform, display name and token expiry.',
    operatorDescription: 'List connected social accounts',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'connect',
    description: 'Start connecting a social provider. Returns the authorization URL the operator has to open in a browser — the platform asks them to log in and approve the app. Takes a provider: meta (Instagram and Facebook together) or linkedin.',
    operatorDescription: 'Start connecting a social account',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'disconnect',
    description: 'Disconnect a connection. Its token is deleted and the accounts it covered can no longer publish.',
    operatorDescription: 'Disconnect a social account',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'refresh',
    description: 'Renew a connection\'s token now. Worth doing before a long break when the token has an expiry.',
    operatorDescription: 'Refresh a social account\'s tokens',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'health',
    description: 'Check whether a connection\'s token still works, and report what the platform answered.',
    operatorDescription: 'Check a social account\'s token health',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'sync',
    description: 'Sync the accounts an aggregator holds. Read-only: it asks the aggregator which accounts the key reaches and updates the plugin\'s copy of them. It never connects an account and never changes anything in the aggregator.',
    operatorDescription: 'Sync the aggregator accounts',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(name: 'provider', type: 'string', description: 'The provider to connect: meta (Instagram and Facebook together) or linkedin.', required: ['connect'], enum: ['meta', 'linkedin'])]
#[ToolParameter(name: 'connection_id', type: 'integer', description: 'The connection id. Required for disconnect, refresh and health.', required: ['disconnect', 'refresh', 'health'])]
#[ToolParameter(name: 'aggregator', type: 'string', description: 'The aggregator to sync: zernio.', required: ['sync'], enum: ['zernio'])]
final class SocialConnectionsTool extends AbstractSocialTool
{
    private const CONNECTION_ID_REQUIRED = 'Error (VALIDATION_ERROR): connection_id is required.';

    public function __construct(
        private readonly SocialConnectionService $connections,
        private readonly AggregatorService $aggregators,
        private readonly AggregatorAccountSync $sync,
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
                'list' => $this->list($principalId),
                'connect' => $this->connect($principalId, $arguments),
                'disconnect' => $this->disconnect($principalId, $arguments),
                'refresh' => $this->refresh($principalId, $arguments),
                'health' => $this->health($principalId, $arguments),
                'sync' => $this->sync($principalId, $arguments),
                default => new ToolResult(false, "Invalid action '{$operation}'. Use list, connect, disconnect, refresh, health or sync."),
            };
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? 'list');

        if ($operation === 'connect') {
            return 'Social connect: ' . (string) ($arguments['provider'] ?? '?');
        }

        return 'Social connections: ' . $operation;
    }

    private function list(int $principalId): ToolResult
    {
        $connections = array_map(
            fn($connection): array => SocialConnectionResource::toArray($connection),
            $this->connections->listForPrincipal($principalId),
        );

        return ToolResult::ok(
            SocialToolPresenter::connections($connections),
            ['connections' => $connections],
        );
    }

    private function connect(int $principalId, array $arguments): ToolResult
    {
        $provider = SocialProvider::tryFromName((string) ($arguments['provider'] ?? ''));

        if ($provider === null) {
            return new ToolResult(false, 'Error (VALIDATION_ERROR): provider must be meta or linkedin.');
        }

        $request = $this->connections->startConnect($principalId, $provider);

        return ToolResult::ok(
            'Open this URL in a browser to connect ' . $provider->value . ': ' . $request['authorize_url'],
            $request,
        );
    }

    private function disconnect(int $principalId, array $arguments): ToolResult
    {
        $connectionId = (int) ($arguments['connection_id'] ?? 0);

        if ($connectionId <= 0) {
            return new ToolResult(false, self::CONNECTION_ID_REQUIRED);
        }

        $this->connections->disconnect($connectionId, $principalId);

        return ToolResult::ok('Disconnected connection ' . $connectionId . '.');
    }

    private function refresh(int $principalId, array $arguments): ToolResult
    {
        $connectionId = (int) ($arguments['connection_id'] ?? 0);

        if ($connectionId <= 0) {
            return new ToolResult(false, self::CONNECTION_ID_REQUIRED);
        }

        $connection = $this->connections->refresh($connectionId, $principalId);

        return ToolResult::ok(
            $this->refreshedMessage($connection),
            ['connection' => SocialConnectionResource::toArray($connection)],
        );
    }

    /**
     * What the refresh did.
     *
     * A Meta token has no refresh grant — it is renewed by connecting
     * again, not by a refresh — so an answer that said "refreshed" would
     * be claiming something that did not happen. An aggregator's key has
     * no grant either: it is replaced by pasting a new one.
     */
    private function refreshedMessage(SocialConnection $connection): string
    {
        if ($connection->provider === SocialProvider::Meta->value) {
            return 'A Meta token has no refresh grant: it is renewed by connecting again, not by refreshing. '
                . ($connection->expires_at === null
                    ? 'This one never expires.'
                    : 'This one expires on ' . $connection->expires_at->toIso8601String() . '.');
        }

        if (AggregatorService::providerOf($connection) !== null) {
            return 'An aggregator key has no refresh grant: it is replaced by pasting a new one on the Accounts screen, '
                . 'not by refreshing. This one never expires.';
        }

        return 'Refreshed the token of connection ' . $connection->id . '.';
    }

    private function health(int $principalId, array $arguments): ToolResult
    {
        $connectionId = (int) ($arguments['connection_id'] ?? 0);

        if ($connectionId <= 0) {
            return new ToolResult(false, self::CONNECTION_ID_REQUIRED);
        }

        $health = $this->connections->health($connectionId, $principalId);

        return ToolResult::ok(
            'Token health: ' . $health['status'] . ' — ' . $health['message'],
            ['health' => $health],
        );
    }

    /**
     * Read the aggregator's accounts again.
     *
     * The accounts are the aggregator's, and the operator manages them
     * there. The sync only learns what is there, so it needs no approval
     * — it cannot publish, and it cannot connect anything.
     */
    private function sync(int $principalId, array $arguments): ToolResult
    {
        $provider = AggregatorProvider::tryFromName((string) ($arguments['aggregator'] ?? ''));

        if ($provider === null) {
            return new ToolResult(false, 'Error (VALIDATION_ERROR): aggregator must be zernio.');
        }

        $connection = $this->aggregators->connection($principalId, $provider);

        if ($connection === null) {
            return new ToolResult(false, 'There is no ' . $provider->label() . ' connection to sync. The operator adds the key on the Accounts screen.');
        }

        $result = $this->sync->read($connection);
        $this->sync->apply($connection, $result);

        $message = 'Synced ' . count($result->accounts) . ' ' . $provider->label() . ' account'
            . (count($result->accounts) === 1 ? '' : 's') . '.';

        if ($result->gone !== []) {
            $message .= ' ' . count($result->gone) . ' account'
                . (count($result->gone) === 1 ? ' is' : 's are') . ' no longer in '
                . $provider->label() . ' and are marked disconnected.';
        }

        if (!$result->analyticsAvailable) {
            $message .= ' The follower counts and the numbers stay empty: analytics is an add-on on '
                . $provider->label() . '\'s plan.';
        }

        return ToolResult::ok($message, [
            'accounts'     => count($result->accounts),
            'disconnected' => count($result->gone),
            'analytics'    => $result->analyticsAvailable,
        ]);
    }
}
