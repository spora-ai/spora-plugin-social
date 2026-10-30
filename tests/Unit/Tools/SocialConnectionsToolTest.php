<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Tests\Live\Support\LiveCredentials;
use Spora\Plugins\Social\Tests\Live\Support\LiveVariableNames;
use Spora\Plugins\Social\Tests\Support\FakeOAuthProvider;
use Spora\Plugins\Social\Tests\Support\FakeProviderResolver;
use Spora\Plugins\Social\Tools\SocialConnectionsTool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * The names the operator and the agent read first.
 *
 * A name that does not exist costs the operator a run, and an agent that
 * reads a schema picks a name over a description.
 */
it('names the credential variables the harness actually reads', function () {
    $names = new LiveVariableNames();

    // Meta's one login covers Instagram and Facebook, so the credentials
    // are the provider's.
    expect($names->clientIdName(SocialPlatform::Facebook))->toBe('SPORA_LIVE_META_CLIENT_ID')
        ->and($names->clientIdName(SocialPlatform::Instagram))->toBe('SPORA_LIVE_META_CLIENT_ID')
        ->and($names->clientSecretName(SocialPlatform::Facebook))->toBe('SPORA_LIVE_META_CLIENT_SECRET')
        ->and($names->clientIdName(SocialPlatform::LinkedIn))->toBe('SPORA_LIVE_LINKEDIN_CLIENT_ID');
});

it('reads the redirect url from the provider name, and accepts the platform name', function () {
    $credentials = new LiveCredentials();

    putenv('SPORA_LIVE_META_REDIRECT_URL=https://spora.test/redirect');

    try {
        $redirect = $credentials->redirectUrl('meta');

        expect($redirect['url'])->toBe('https://spora.test/redirect')
            ->and($redirect['variable'])->toBe('SPORA_LIVE_META_REDIRECT_URL');
    } finally {
        putenv('SPORA_LIVE_META_REDIRECT_URL');
    }

    // The old spelling still works, so an operator who guessed it is not
    // left stuck — and the harness says which one it read.
    putenv('SPORA_LIVE_FACEBOOK_REDIRECT_URL=https://spora.test/old');

    try {
        $redirect = $credentials->redirectUrl('meta');

        expect($redirect['url'])->toBe('https://spora.test/old')
            ->and($redirect['variable'])->toBe('SPORA_LIVE_FACEBOOK_REDIRECT_URL');
    } finally {
        putenv('SPORA_LIVE_FACEBOOK_REDIRECT_URL');
    }
});

it('names the connection parameter after the connection', function () {
    $parameters = [];

    foreach ((new ReflectionClass(SocialConnectionsTool::class))->getAttributes(ToolParameter::class) as $attribute) {
        $parameter = $attribute->newInstance();
        $parameters[$parameter->name] = $parameter;
    }

    // The agent reads the name, not the description, and a connection is
    // not an account.
    expect($parameters)->toHaveKey('connection_id')
        ->and($parameters)->not->toHaveKey('account_id');
});

it('says that a meta connection is not refreshed but reconnected', function () {
    $fixture = aPrincipalWithAConnection(
        ['meta' => new FakeOAuthProvider(SocialProvider::Meta)],
        'meta',
        ['access_token' => 'the-suat'],
    );

    $result = theToolAnswers($fixture['tool'], 'refresh', $fixture['connectionId'], $fixture['principalId']);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('no refresh grant')
        ->and($result->content)->toContain('never expires');
});

it('says that a linkedin connection was refreshed', function () {
    $fixture = aPrincipalWithAConnection(
        ['linkedin' => new FakeOAuthProvider(SocialProvider::LinkedIn)],
        'linkedin',
        ['access_token' => 'the-token', 'expires_at' => new DateTimeImmutable('+50 days')],
    );

    $result = theToolAnswers($fixture['tool'], 'refresh', $fixture['connectionId'], $fixture['principalId']);

    expect($result->content)->toContain('Refreshed the token')
        ->and($result->success)->toBeTrue();
});

/**
 * A principal with a connection, and the tool that acts for them.
 *
 * @param  array<string, Spora\Plugins\Social\OAuth\OAuthProviderInterface> $providers
 * @param  array<string, mixed>                                                  $overrides the connection's own values
 * @return array{principalId: int, connectionId: int, tool: SocialConnectionsTool}
 */
function aPrincipalWithAConnection(
    array $providers = [],
    string $provider = 'linkedin',
    array $overrides = [],
): array {
    $fixture = aPrincipalWithTheTool($providers);

    $connection = createSocialConnection($fixture['principalId'], $provider, $overrides);

    return $fixture + ['connectionId' => (int) $connection->id];
}

/**
 * The tool's answer to an action.
 *
 * @param  int|null              $connectionId the connection the action is about, when it has one
 * @param  array<string, mixed>  $extra        the arguments the action needs besides its name
 */
function theToolAnswers(
    SocialConnectionsTool $tool,
    string $action,
    ?int $connectionId,
    int $principalId,
    array $extra = [],
): ToolResult {
    $arguments = ['action' => $action] + $extra;

    if ($connectionId !== null) {
        $arguments['connection_id'] = $connectionId;
    }

    return $tool->execute($arguments, 1, null, principalContext($principalId));
}

/**
 * The connections tool, and the principal it acts for.
 *
 * @param  array<string, Spora\Plugins\Social\OAuth\OAuthProviderInterface> $providers
 * @return array{principalId: int, tool: SocialConnectionsTool}
 */
function aPrincipalWithTheTool(array $providers = []): array
{
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    return [
        'principalId' => $principalId,
        'tool' => new SocialConnectionsTool(connectionService(new FakeProviderResolver($providers))),
    ];
}

it('lists the connections of the principal', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    createSocialConnection($principalId, 'linkedin', ['access_token' => 'the-token']);

    $tool = new SocialConnectionsTool(connectionService());

    $result = $tool->execute(['action' => 'list'], 1, null, principalContext($principalId));

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('1 social connection(s)')
        ->and($result->data['connections'])->toHaveCount(1);
});

it('says that no account is connected', function () {
    $fixture = aPrincipalWithTheTool();

    $result = theToolAnswers($fixture['tool'], 'list', null, $fixture['principalId']);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('No social account is connected');
});

it('hands over the url to connect a provider', function () {
    $fixture = aPrincipalWithTheTool([
        'linkedin' => new FakeOAuthProvider(SocialProvider::LinkedIn),
    ]);

    $result = theToolAnswers($fixture['tool'], 'connect', null, $fixture['principalId'], ['provider' => 'linkedin']);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Open this URL in a browser to connect linkedin')
        ->and($result->data['authorize_url'])->toContain('https://');
});

it('refuses a connect without a known provider', function () {
    $fixture = aPrincipalWithTheTool();

    $result = theToolAnswers($fixture['tool'], 'connect', null, $fixture['principalId'], ['provider' => 'tiktok']);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('provider must be meta or linkedin');
});

it('disconnects a connection', function () {
    $fixture = aPrincipalWithAConnection();

    $result = theToolAnswers($fixture['tool'], 'disconnect', $fixture['connectionId'], $fixture['principalId']);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Disconnected connection');
});

it('refuses an action that needs a connection id without one', function () {
    $fixture = aPrincipalWithTheTool();

    foreach (['disconnect', 'health'] as $action) {
        $result = theToolAnswers($fixture['tool'], $action, null, $fixture['principalId']);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('connection_id is required');
    }
});

it('reports the health of a connection', function () {
    $fixture = aPrincipalWithAConnection([
        'linkedin' => new FakeOAuthProvider(SocialProvider::LinkedIn),
    ]);

    $result = theToolAnswers($fixture['tool'], 'health', $fixture['connectionId'], $fixture['principalId']);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Token health:');
});

it('refuses an action it does not know', function () {
    $fixture = aPrincipalWithTheTool();

    $result = theToolAnswers($fixture['tool'], 'destroy', null, $fixture['principalId']);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Invalid action 'destroy'");
});

it('names the action the operator is about to confirm', function () {
    $tool = new SocialConnectionsTool(connectionService());

    expect($tool->describeAction(['action' => 'connect', 'provider' => 'meta']))
        ->toBe('Social connect: meta')
        ->and($tool->describeAction(['action' => 'list']))
        ->toBe('Social connections: list');
});

it('needs a principal to act for', function () {
    $tool = new SocialConnectionsTool(connectionService());

    $result = $tool->execute(['action' => 'list'], 999999);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('This tool needs a principal to act for');
});
