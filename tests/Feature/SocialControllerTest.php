<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Http\OAuthCallbackController;
use Spora\Plugins\Social\Http\SocialCalendarController;
use Spora\Plugins\Social\Http\SocialConnectionController;
use Spora\Plugins\Social\Http\SocialMediaController;
use Spora\Plugins\Social\Http\SocialPostController;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\OAuth\SocialStateCodec;
use Spora\Plugins\Social\Service\PlatformFit;
use Spora\Plugins\Social\Service\SocialAppUrlProbe;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Plugins\Social\Service\SocialPublisher;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Tests\Support\FakeOAuthProvider;
use Spora\Plugins\Social\Tests\Support\FakeProviderResolver;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The controllers, wired the way the container wires them.
 */
function connectionController(
    AuthService $auth,
    ?SocialSettings $settings = null,
    ?SocialConnectionService $connections = null,
): SocialConnectionController {
    $resolver = new PrincipalResolver();
    $principalService = new PrincipalService($resolver);

    return new SocialConnectionController(
        $auth,
        $resolver,
        $principalService,
        $connections ?? connectionService(),
        $settings ?? new SocialSettings(socialConfig()),
        new SocialAppUrlProbe(new MockHttpClient([new MockResponse('', ['http_code' => 200])])),
        aggregatorService(),
    );
}

function postController(AuthService $auth, ?SocialPublisher $publisher = null): SocialPostController
{
    $resolver = new PrincipalResolver();

    return new SocialPostController(
        $auth,
        $resolver,
        new PrincipalService($resolver),
        postService(),
        $publisher ?? postPublisher(),
        metricsService([]),
    );
}

function calendarController(AuthService $auth): SocialCalendarController
{
    $resolver = new PrincipalResolver();

    return new SocialCalendarController(
        $auth,
        $resolver,
        new PrincipalService($resolver),
        postService(),
    );
}

function mediaController(AuthService $auth): SocialMediaController
{
    $resolver = new PrincipalResolver();

    return new SocialMediaController(
        $auth,
        $resolver,
        new PrincipalService($resolver),
        mediaPipeline(),
        postService(),
    );
}

/**
 * @return array<string, mixed>
 */
function decode(JsonResponse $response): array
{
    return (array) json_decode((string) $response->getContent(), true);
}

it('answers with a 422 when the request names no principal', function () {
    $auth = bootAuthLayer();

    $response = connectionController($auth)->connections(jsonRequest('GET', '/api/v1/plugins/social/connections'));

    expect($response->getStatusCode())->toBe(422);

    $body = decode($response);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR')
        ->and($body['error']['message'])->toContain('principal_id is required');
});

it('answers with a 403 for a principal the caller cannot act for', function () {
    $auth = bootAuthLayer();
    $callerId = bootAuth($auth);
    $foreignId = createUserPrincipal(bootAuth($auth, 'other@example.test'));

    // The second login replaced the session; the caller is the first one.
    simulateLoggedInSession($callerId, 'test@example.com');

    $response = connectionController($auth)->connections(
        jsonRequest('GET', '/api/v1/plugins/social/connections?principal_id=' . $foreignId),
    );

    expect($response->getStatusCode())->toBe(403);

    $body = decode($response);
    expect($body['error']['code'])->toBe('FORBIDDEN');
});

it('answers with the connections of the principal, each with its accounts', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $response = connectionController($auth)->connections(
        jsonRequest('GET', '/api/v1/plugins/social/connections?principal_id=' . $principalId),
    );

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response);
    expect($body['data'])->toHaveCount(1)
        ->and($body['data'][0]['provider'])->toBe('linkedin')
        ->and($body['data'][0]['accounts'])->toHaveCount(1)
        ->and($body['data'][0]['accounts'][0]['id'])->toBe($account->id)
        ->and($body['data'][0]['accounts'][0]['platform'])->toBe('linkedin');
});

it('carries the redirect uri the operator has to register', function () {
    $auth = bootAuthLayer();
    bootAuth($auth);

    $response = connectionController($auth)->config(jsonRequest('GET', '/api/v1/plugins/social/config'));

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response);

    // The accounts screen shows it so the operator can copy it into the
    // platform's own form.
    expect($body['data']['providers']['meta']['redirect_uri'])
        ->toBe('https://spora.test/api/v1/plugins/social/oauth/meta/callback')
        ->and($body['data']['providers']['linkedin']['redirect_uri'])
        ->toBe('https://spora.test/api/v1/plugins/social/oauth/linkedin/callback');
});

it('carries no redirect uri on an instance that cannot register one', function () {
    $auth = bootAuthLayer();
    bootAuth($auth);

    // A local address without the development switch: no platform accepts
    // it, so naming it would send the operator to register something that
    // cannot work.
    $settings = new SocialSettings(socialConfig(['SPORA_APP_URL' => 'http://localhost:8080']));

    $response = connectionController($auth, $settings)->config(jsonRequest('GET', '/api/v1/plugins/social/config'));

    $body = decode($response);

    // The key is there and it is empty: an absent key would say the same
    // thing about an endpoint that never sent one.
    expect($body['data']['providers']['meta'])->toHaveKey('redirect_uri')
        ->and($body['data']['providers']['meta']['redirect_uri'])->toBeNull()
        ->and($body['data']['providers']['linkedin']['redirect_uri'])->toBeNull();
});

it('carries no setup notice for a provider that needs no setup', function () {
    $auth = bootAuthLayer();
    bootAuth($auth);

    $response = connectionController($auth)->config(jsonRequest('GET', '/api/v1/plugins/social/config'));

    $body = decode($response);

    // A notice is a notice because something is missing.
    expect($body['data']['providers']['meta']['needs_setup'])->toBeNull()
        ->and($body['data']['providers']['linkedin']['needs_setup'])->toBeNull();
});

it('carries the setup notice when the meta configuration id is missing', function () {
    $auth = bootAuthLayer();
    bootAuth($auth);

    // The app id and the secret are there; the configuration id is not.
    $settings = new SocialSettings(socialConfig([
        SocialSettings::KEY_META_CONFIG_ID => '',
    ]));

    $response = connectionController($auth, $settings)->config(jsonRequest('GET', '/api/v1/plugins/social/config'));

    $body = decode($response);

    expect($body['data']['providers']['meta']['needs_setup'])->toBe([
        'title' => 'Meta integration needs setup',
        'body'  => 'Add the app id, the app secret and the configuration id in Settings → Plugins.',
    ])->and($body['data']['providers']['linkedin']['needs_setup'])->toBeNull();
});

it('answers with the configuration of the operator', function () {
    $auth = bootAuthLayer();
    bootAuth($auth);

    $response = connectionController($auth)->config(jsonRequest('GET', '/api/v1/plugins/social/config'));

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response);
    expect($body['data']['providers']['linkedin']['configured'])->toBeTrue()
        ->and($body['data']['providers']['linkedin']['client_id'])->toBe('linkedin-id')
        ->and($body['data']['providers']['meta']['configured'])->toBeTrue()
        ->and($body['data']['providers']['meta']['client_id'])->toBe('meta-app-id')
        ->and($body['data']['providers']['meta']['config_id'])->toBe('meta-config-id')
        ->and($body['data']['app_url'])->toBe('https://spora.test')
        ->and($body['data'])->toHaveKey('app_url_public');
});

it('answers with a 422 when a write names no principal', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $response = postController($auth)->createPost(jsonRequest('POST', SocialFixtures::PLUGIN_POSTS_PATH, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
    ]));

    expect($response->getStatusCode())->toBe(422)
        ->and(decode($response)['error']['code'])->toBe('VALIDATION_ERROR');
});

it('creates a post and answers with the post', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $response = postController($auth)->createPost(jsonRequest('POST', SocialFixtures::PLUGIN_POSTS_PATH, [
        'principal_id' => $principalId,
        'body'         => SocialFixtures::POST_BODY,
        'account_ids'  => [$account->id],
    ]));

    expect($response->getStatusCode())->toBe(201);

    $body = decode($response);
    expect($body['data']['status'])->toBe('draft')
        ->and($body['data']['body'])->toBe(SocialFixtures::POST_BODY)
        ->and($body['data']['targets'])->toHaveCount(1);
});

it('takes a published post back down and answers with the post', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $controller = postController($auth, postPublisher([
        'linkedin' => [
            ['body' => '{"id":"urn:li:share:9"}'],
            ['body' => '', 'status' => 204],
        ],
    ]));

    $created = decode($controller->createPost(jsonRequest('POST', SocialFixtures::PLUGIN_POSTS_PATH, [
        'principal_id' => $principalId,
        'body'         => SocialFixtures::POST_BODY,
        'account_ids'  => [$account->id],
    ])));

    $postId = $created['data']['id'];

    $publish = jsonRequest('POST', SocialFixtures::PLUGIN_POSTS_PATH . '/' . $postId . '/publish', [
        'principal_id' => $principalId,
    ]);
    $publish->attributes->set('id', $postId);

    $controller->publishPost($publish);

    $request = jsonRequest(
        'DELETE',
        SocialFixtures::PLUGIN_POSTS_PATH . '/' . $postId . '/published?principal_id=' . $principalId,
    );
    $request->attributes->set('id', $postId);

    $response = $controller->deletePublished($request);

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response);
    expect($body['data']['status'])->toBe('cancelled')
        ->and($body['data']['targets'][0]['status'])->toBe('deleted');
});

it('answers with the posts of the principal and a total', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $controller = postController($auth);

    $controller->createPost(jsonRequest('POST', SocialFixtures::PLUGIN_POSTS_PATH, [
        'principal_id' => $principalId,
        'body'         => SocialFixtures::POST_BODY,
        'account_ids'  => [$account->id],
    ]));

    $response = $controller->posts(
        jsonRequest('GET', '/api/v1/plugins/social/posts?principal_id=' . $principalId),
    );

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response);
    expect($body['data']['total'])->toBe(1)
        ->and($body['data']['items'])->toHaveCount(1);
});

it('lists the media of the principal with the url the host renders as an image', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/png', 'payload' => pngBytes()]);

    $response = mediaController($auth)->media(
        jsonRequest('GET', '/api/v1/plugins/social/media?principal_id=' . $principalId),
    );

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response);
    expect($body['data'])->toHaveCount(1)
        ->and($body['data'][0]['id'])->toBe($asset->id)
        ->and($body['data'][0]['asset_url'])->toBe($asset->asset_url)
        ->and($body['data'][0]['asset_url'])->toStartWith('/api/v1/assets/');
});

it('checks the fit of an asset as the platform receives it', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/png', 'payload' => pngBytes()]);

    $response = mediaController($auth)->checkFit(jsonRequest('POST', '/api/v1/plugins/social/media/check-fit', [
        'principal_id'   => $principalId,
        'media_asset_id' => $asset->id,
        'channels'       => ['direct:instagram'],
    ]));

    expect($response->getStatusCode())->toBe(200);

    // Instagram takes no PNG, but the backend converts one before it
    // publishes — so the asset fits there, as the publish will find it.
    $body = decode($response);
    expect($body['data']['fits'])->toBeTrue()
        ->and($body['data']['per_channel'][0]['channel'])->toBe('direct:instagram')
        ->and($body['data']['per_channel'][0]['network'])->toBe('instagram')
        ->and($body['data']['per_channel'][0]['via'])->toBe('direct')
        ->and($body['data']['per_channel'][0]['mime_type'])->toBe('image/jpeg');
});

it('names the remedy for a media Instagram cannot take at all', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $asset = createMediaAsset($principalId, ['mime_type' => 'text/markdown', 'payload' => '# hello']);

    $response = mediaController($auth)->checkFit(jsonRequest('POST', '/api/v1/plugins/social/media/check-fit', [
        'principal_id'   => $principalId,
        'media_asset_id' => $asset->id,
        'channels'       => ['direct:instagram'],
    ]));

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response);
    expect($body['data']['fits'])->toBeFalse()
        ->and($body['data']['per_channel'][0]['remedy'])->toBe('Convert to JPEG');
});

it('answers with the month of the calendar', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $response = calendarController($auth)->calendar(
        jsonRequest('GET', SocialFixtures::PLUGIN_CALENDAR_PATH . SocialFixtures::PRINCIPAL_QUERY . $principalId . '&month=2026-11'),
    );

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response);
    expect($body['data'])->toHaveKey('posts')
        ->and($body['data'])->not->toHaveKey('days');
});

it('carries the platform rules the frontend reads', function () {
    $auth = bootAuthLayer();
    bootAuth($auth);

    $response = connectionController($auth)->config(jsonRequest('GET', '/api/v1/plugins/social/config'));

    expect($response->getStatusCode())->toBe(200);

    $rules = decode($response)['data']['rules'];

    expect($rules)->toHaveKeys(['direct:linkedin', 'direct:facebook', 'direct:instagram', 'zernio:bluesky']);

    foreach (PlatformFit::channels() as $channel) {
        $rule = PlatformFit::rule($channel);

        expect($rules[$channel->key()]['media_required'])->toBe($rule['media_required'])
            ->and($rules[$channel->key()]['media_count'])->toBe($rule['media_count'])
            ->and($rules[$channel->key()]['fetches_media'])->toBe($rule['fetches_media'])
            ->and($rules[$channel->key()]['mime_types'])->toBe($rule['mime_types'])
            ->and($rules[$channel->key()]['max_bytes'])->toBe($rule['max_bytes'])
            ->and($rules[$channel->key()]['max_text'])->toBe($rule['max_characters'])
            ->and($rules[$channel->key()]['media_note'])->toContain($rule['media'])
            // The network names live in one place: the frontend stops
            // keeping its own list of them.
            ->and($rules[$channel->key()]['label'])->toBe($rule['label'])
            ->and($rules[$channel->key()]['network'])->toBe($channel->network)
            ->and($rules[$channel->key()]['via'])->toBe($channel->via)
            ->and($rules[$channel->key()])->toHaveKeys([
                'label',
                'network',
                'via',
                'media_required',
                'media_count',
                'fetches_media',
                'mime_types',
                'max_bytes',
                'max_text',
                'visible_chars',
                'media_note',
                'media_remedy',
                'video_remedy',
                'size_remedy',
            ]);
    }
});

it('carries the aggregators beside the oauth providers, not among them', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $response = connectionController($auth)->config(
        jsonRequest('GET', '/api/v1/plugins/social/config' . SocialFixtures::PRINCIPAL_QUERY . $principalId),
    );

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response)['data'];

    // An aggregator is not an OAuth provider: it has no client id and no
    // redirect URI, so it gets its own section.
    expect($body['aggregators'])->toHaveKey('zernio')
        ->and($body['aggregators']['zernio'])->toBe([
            'configured'  => false,
            'label'       => 'Zernio',
            'needs_setup' => null,
        ])
        ->and($body['providers'])->not->toHaveKey('zernio');
});

it('says the aggregator is configured once the operator has pasted a key', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    createSocialConnection($principalId, 'zernio');

    $response = connectionController($auth)->config(
        jsonRequest('GET', '/api/v1/plugins/social/config' . SocialFixtures::PRINCIPAL_QUERY . $principalId),
    );

    expect(decode($response)['data']['aggregators']['zernio']['configured'])->toBeTrue();
});

/**
 * The OAuth callback, which the platform's browser reaches without a session.
 */
function oauthCallbackController(?SocialConnectionService $connections = null): OAuthCallbackController
{
    return new OAuthCallbackController(
        $connections ?? connectionService(),
        new SocialSettings(socialConfig()),
    );
}

/**
 * A signed state the callback accepts.
 */
function aCallbackState(int $principalId, string $provider = 'linkedin'): string
{
    return (new SocialStateCodec(securityManager()))->encode([
        'principal_id' => $principalId,
        'provider'     => $provider,
        'nonce'        => 'the-nonce',
        'expires_at'   => time() + 600,
    ]);
}

it('sends the browser back to the app when the platform returns', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $connections = connectionService(new FakeProviderResolver([
        'linkedin' => new FakeOAuthProvider(SocialProvider::LinkedIn),
    ]));

    $request = Request::create(SocialFixtures::PLUGIN_CALLBACK_PATH, 'GET', [
        'code'  => 'the-code',
        'state' => aCallbackState($principalId),
    ]);
    $request->attributes->set('provider', 'linkedin');

    $response = oauthCallbackController($connections)->callback($request);

    expect($response->getStatusCode())->toBe(302);
    expect((string) $response->headers->get('Location'))->toContain('/apps/social/p/' . $principalId . '/connections');
});

it('refuses a callback for a provider it does not know', function () {
    $request = Request::create('/api/v1/plugins/social/oauth/tiktok/callback', 'GET', [
        'code'  => 'the-code',
        'state' => 'the-state',
    ]);
    $request->attributes->set('provider', 'tiktok');

    expect(fn() => oauthCallbackController()->callback($request))
        ->toThrow(SocialException::class, 'known provider');
});

it('refuses a callback without a code or a state', function () {
    $request = Request::create(SocialFixtures::PLUGIN_CALLBACK_PATH, 'GET');
    $request->attributes->set('provider', 'linkedin');

    expect(fn() => oauthCallbackController()->callback($request))
        ->toThrow(SocialException::class, 'code or its state');
});

it('answers with the failure when the connect cannot be finished', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $connections = connectionService(new FakeProviderResolver([
        'linkedin' => new FakeOAuthProvider(SocialProvider::LinkedIn, refusal: 'the platform said no'),
    ]));

    $request = Request::create(SocialFixtures::PLUGIN_CALLBACK_PATH, 'GET', [
        'code'  => 'the-code',
        'state' => aCallbackState($principalId),
    ]);
    $request->attributes->set('provider', 'linkedin');

    $response = oauthCallbackController($connections)->callback($request);

    $body = (array) json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(400)
        ->and($body['error']['message'])->toContain('the platform said no');
});

it('starts a connect from the endpoint', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $response = connectionController($auth, null, connectionService(new FakeProviderResolver([
        'linkedin' => new FakeOAuthProvider(SocialProvider::LinkedIn),
    ])))->connect(jsonRequest('POST', '/api/v1/plugins/social/connections/connect', [
        'principal_id' => $principalId,
        'provider'     => 'linkedin',
    ]));

    expect($response->getStatusCode())->toBe(200);
    expect(decode($response)['data'])->toHaveKeys(['authorize_url', 'state', 'expires_at']);
});

it('refuses a connect for a provider it does not know', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $response = connectionController($auth)->connect(jsonRequest('POST', '/api/v1/plugins/social/connections/connect', [
        'principal_id' => $principalId,
        'provider'     => 'tiktok',
    ]));

    expect($response->getStatusCode())->toBe(422)
        ->and(decode($response)['error']['message'])->toContain('meta or linkedin');
});

it('disconnects a connection from the endpoint', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $connection = createSocialConnection($principalId, 'linkedin', ['access_token' => 'the-token']);

    $request = Request::create(SocialFixtures::PLUGIN_CONNECTIONS_PATH . '/' . $connection->id . SocialFixtures::PRINCIPAL_QUERY . $principalId, 'DELETE');
    $request->attributes->set('id', $connection->id);

    $response = connectionController($auth)->disconnect($request);

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data']['deleted'])->toBeTrue();
});

it('renews a token from the endpoint', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $connection = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'the-token',
        'expires_at'   => new DateTimeImmutable('+50 days'),
    ]);

    $request = Request::create(SocialFixtures::PLUGIN_CONNECTIONS_PATH . '/' . $connection->id . '/refresh?principal_id=' . $principalId, 'POST');
    $request->attributes->set('id', $connection->id);

    $response = connectionController($auth, null, connectionService(new FakeProviderResolver([
        'linkedin' => new FakeOAuthProvider(SocialProvider::LinkedIn),
    ])))->refresh($request);

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data']['status'])->toBe('active');
});

it('asks the platform whether a token still works', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $connection = createSocialConnection($principalId, 'linkedin', ['access_token' => 'the-token']);

    $request = Request::create(SocialFixtures::PLUGIN_CONNECTIONS_PATH . '/' . $connection->id . '/health?principal_id=' . $principalId, 'POST');
    $request->attributes->set('id', $connection->id);

    $response = connectionController($auth, null, connectionService(new FakeProviderResolver([
        'linkedin' => new FakeOAuthProvider(SocialProvider::LinkedIn),
    ])))->health($request);

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data'])->toHaveKeys(['status', 'message', 'checked_at']);
});

/**
 * A post of the principal, with an account it goes to.
 *
 * @return array{principalId: int, accountId: int, postId: int}
 */
function aPostOfThePrincipal(): array
{
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = postService()->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
    ]);

    return ['principalId' => $principalId, 'accountId' => $account->id, 'postId' => (int) $post->id];
}

/**
 * A request for one post of the principal.
 */
function aRequestForAPost(int $principalId, int $postId, string $method = 'GET'): Request
{
    $request = Request::create(SocialFixtures::PLUGIN_POSTS_PATH . '/' . $postId . SocialFixtures::PRINCIPAL_QUERY . $principalId, $method);
    $request->attributes->set('id', $postId);

    return $request;
}

it('answers with one post', function () {
    $auth = bootAuthLayer();
    $post = aPostOfThePrincipal();

    $response = postController($auth)->post(aRequestForAPost($post['principalId'], $post['postId']));

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data']['id'])->toBe($post['postId']);
});

it('answers with a 404 for a post that is not there', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $response = postController($auth)->post(aRequestForAPost($principalId, 999999));

    expect($response->getStatusCode())->toBe(404);
});

it('changes a post', function () {
    $auth = bootAuthLayer();
    $post = aPostOfThePrincipal();

    $request = Request::create(
        '/api/v1/plugins/social/posts/' . $post['postId'] . '?principal_id=' . $post['principalId'],
        'PATCH',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['title' => 'A better title']),
    );
    $request->attributes->set('id', $post['postId']);

    $response = postController($auth)->updatePost($request);

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data']['title'])->toBe('A better title');
});

it('deletes a post', function () {
    $auth = bootAuthLayer();
    $post = aPostOfThePrincipal();

    $response = postController($auth)->deletePost(aRequestForAPost($post['principalId'], $post['postId'], 'DELETE'));

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data']['deleted'])->toBeTrue();
});

it('publishes a post from the endpoint', function () {
    $auth = bootAuthLayer();
    $post = aPostOfThePrincipal();

    $response = postController($auth)->publishPost(aRequestForAPost($post['principalId'], $post['postId'], 'POST'));

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data'])->toHaveKey('targets');
});

it('retries the failed targets of a post from the endpoint', function () {
    $auth = bootAuthLayer();
    $post = aPostOfThePrincipal();

    $response = postController($auth)->retryPost(aRequestForAPost($post['principalId'], $post['postId'], 'POST'));

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data'])->toHaveKey('status');
});

it('publishes a post the moment it is created', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));
    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $response = postController($auth, postPublisher([
        'linkedin' => [['body' => '{"id":"urn:li:share:9"}']],
    ]))->createPost(jsonRequest('POST', '/api/v1/plugins/social/posts', [
        'principal_id' => $principalId,
        'body'         => SocialFixtures::POST_BODY,
        'account_ids'  => [$account->id],
        'publish_now'  => true,
    ]));

    expect($response->getStatusCode())->toBe(201)
        ->and(decode($response)['data']['status'])->toBe('published');
});

it('answers with the summary of the analytics', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $response = postController($auth)->analyticsSummary(
        Request::create('/api/v1/plugins/social/analytics/summary?principal_id=' . $principalId . '&days=7'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data'])->toHaveKeys(['totals', 'by_network', 'series', 'top_posts', 'notes']);
});

it('answers with the numbers of one post', function () {
    $auth = bootAuthLayer();
    $post = aPostOfThePrincipal();

    $request = Request::create('/api/v1/plugins/social/analytics/posts/' . $post['postId'] . '?principal_id=' . $post['principalId']);
    $request->attributes->set('postId', $post['postId']);

    $response = postController($auth)->postAnalytics($request);

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data']['post_id'])->toBe($post['postId']);
});

it('prepares an image for instagram', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/jpeg', 'payload' => jpegBytes()]);

    $response = mediaController($auth)->prepareMedia(jsonRequest('POST', '/api/v1/plugins/social/media/prepare', [
        'principal_id'  => $principalId,
        'media_asset_id' => $asset->id,
        'platform'      => 'instagram',
    ]));

    expect($response->getStatusCode())->toBe(200);
    expect(decode($response)['data'])->toHaveKeys(['media_asset_id', 'format', 'url', 'mime_type']);
});

it('attaches an image to a post and takes it off again', function () {
    $auth = bootAuthLayer();
    $post = aPostOfThePrincipal();
    $principalId = $post['principalId'];

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/jpeg', 'payload' => jpegBytes()]);

    $attached = mediaController($auth)->attachMedia(jsonRequest('POST', '/api/v1/plugins/social/media/attach', [
        'principal_id'  => $principalId,
        'post_id'       => $post['postId'],
        'media_asset_id' => $asset->id,
    ]));

    expect($attached->getStatusCode())->toBe(200)
        ->and(decode($attached)['data']['media'])->toHaveCount(1);

    $detached = mediaController($auth)->detachMedia(jsonRequest('POST', '/api/v1/plugins/social/media/detach', [
        'principal_id'  => $principalId,
        'post_id'       => $post['postId'],
        'media_asset_id' => $asset->id,
    ]));

    expect($detached->getStatusCode())->toBe(200)
        ->and(decode($detached)['data']['media'])->toHaveCount(0);
});

it('refuses an attachment without an asset', function () {
    $auth = bootAuthLayer();
    $post = aPostOfThePrincipal();

    $response = mediaController($auth)->attachMedia(jsonRequest('POST', '/api/v1/plugins/social/media/attach', [
        'principal_id' => $post['principalId'],
        'post_id'      => $post['postId'],
    ]));

    expect($response->getStatusCode())->toBe(422)
        ->and(decode($response)['error']['message'])->toContain('media_asset_id is required');
});

it('answers with the calendar of a month', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $response = calendarController($auth)->calendar(
        Request::create(SocialFixtures::PLUGIN_CALENDAR_PATH . SocialFixtures::PRINCIPAL_QUERY . $principalId . '&month=2026-11'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data'])->toHaveKeys(['posts', 'total']);
});

it('carries the channels every post of the month goes to', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    // A LinkedIn member profile on the plugin's own route and a Bluesky
    // account through the aggregator: two channels, two accounts.
    $direct = accountOnRoute($principalId, 'linkedin', 'direct', 'urn:li:organization:1');
    $aggregated = accountOnRoute($principalId, 'bluesky', 'zernio', 'id_002');

    postService()->create($principalId, [
        'title'         => SocialFixtures::POST_TITLE,
        'body'          => SocialFixtures::POST_BODY,
        'account_ids'   => [$direct->id, $aggregated->id],
        'scheduled_for' => SocialFixtures::CALENDAR_MOMENT,
    ]);

    $response = calendarController($auth)->calendar(
        Request::create(SocialFixtures::PLUGIN_CALENDAR_PATH . SocialFixtures::PRINCIPAL_QUERY . $principalId . '&month=' . SocialFixtures::CALENDAR_MONTH),
    );

    expect($response->getStatusCode())->toBe(200);

    $post = decode($response)['data']['posts'][0];

    // One entry per target, and the entry names the route as well as the
    // network: a post that names only the network cannot say which of the
    // two routes it goes out through.
    expect($post['channels'])->toHaveCount(2)
        ->and($post['channels'][0]['key'])->toBe('direct:linkedin')
        ->and($post['channels'][0]['network'])->toBe('linkedin')
        ->and($post['channels'][0]['via'])->toBe('direct')
        ->and($post['channels'][1]['key'])->toBe('zernio:bluesky')
        ->and($post['channels'][1]['network'])->toBe('bluesky')
        ->and($post['channels'][1]['via'])->toBe('zernio');
});

it('falls back to this month when the calendar names none', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $response = calendarController($auth)->calendar(
        Request::create(SocialFixtures::PLUGIN_CALENDAR_PATH . SocialFixtures::PRINCIPAL_QUERY . $principalId . '&month=nonsense'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(decode($response)['data']['posts'])->toBe([]);
});
