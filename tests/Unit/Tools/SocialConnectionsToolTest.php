<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Tests\Live\Support\LiveCredentials;
use Spora\Plugins\Social\Tests\Support\FakeOAuthProvider;
use Spora\Plugins\Social\Tests\Support\FakeProviderResolver;
use Spora\Plugins\Social\Tools\SocialConnectionsTool;
use Spora\Tools\Attributes\ToolParameter;

/**
 * The names the operator and the agent read first.
 *
 * A name that does not exist costs the operator a run, and an agent that
 * reads a schema picks a name over a description.
 */
it('names the credential variables the harness actually reads', function () {
    $credentials = new LiveCredentials();

    // Meta's one login covers Instagram and Facebook, so the credentials
    // are the provider's.
    expect($credentials->clientIdName(SocialPlatform::Facebook))->toBe('SPORA_LIVE_META_CLIENT_ID')
        ->and($credentials->clientIdName(SocialPlatform::Instagram))->toBe('SPORA_LIVE_META_CLIENT_ID')
        ->and($credentials->clientSecretName(SocialPlatform::Facebook))->toBe('SPORA_LIVE_META_CLIENT_SECRET')
        ->and($credentials->clientIdName(SocialPlatform::LinkedIn))->toBe('SPORA_LIVE_LINKEDIN_CLIENT_ID');
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
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'meta', ['access_token' => 'the-suat']);

    $tool = new SocialConnectionsTool(connectionService(new FakeProviderResolver([
        'meta' => new FakeOAuthProvider(SocialProvider::Meta),
    ])));

    $result = $tool->execute(
        ['action' => 'refresh', 'connection_id' => $connection->id],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('no refresh grant')
        ->and($result->content)->toContain('never expires');
});

it('says that a linkedin connection was refreshed', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'the-token',
        'expires_at'   => new DateTimeImmutable('+50 days'),
    ]);

    $tool = new SocialConnectionsTool(connectionService(new FakeProviderResolver([
        'linkedin' => new FakeOAuthProvider(SocialProvider::LinkedIn),
    ])));

    $result = $tool->execute(
        ['action' => 'refresh', 'connection_id' => $connection->id],
        1,
        null,
        principalContext($principalId),
    );

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Refreshed the token');
});
