<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Plugins\Social\Http\SocialCalendarController;
use Spora\Plugins\Social\Http\SocialConnectionController;
use Spora\Plugins\Social\Http\SocialMediaController;
use Spora\Plugins\Social\Http\SocialPostController;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Service\PlatformFit;
use Spora\Plugins\Social\Service\SocialAppUrlProbe;
use Spora\Plugins\Social\Service\SocialPublisher;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The controllers, wired the way the container wires them.
 */
function connectionController(AuthService $auth, ?SocialSettings $settings = null): SocialConnectionController
{
    $resolver = new PrincipalResolver();
    $principalService = new PrincipalService($resolver);

    return new SocialConnectionController(
        $auth,
        $resolver,
        $principalService,
        connectionService(),
        $settings ?? new SocialSettings(socialConfig()),
        new SocialAppUrlProbe(new MockHttpClient([new MockResponse('', ['http_code' => 200])])),
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
        new Spora\Plugins\Social\Service\SocialDraftService(
            new Spora\Services\LLMConfigPreferences(),
            new Spora\Drivers\DriverFactory(
                new Monolog\Logger('test'),
                new Spora\Services\LLMConfigService(
                    new Spora\Core\SecurityManager(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                ),
            ),
        ),
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
    $principalId = createUserPrincipal(bootAuth($auth));

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

it('does not carry the redirect uri, which is setup instruction', function () {
    $auth = bootAuthLayer();
    bootAuth($auth);

    $response = connectionController($auth)->config(jsonRequest('GET', '/api/v1/plugins/social/config'));

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response);

    // The URI is registered on the setting it belongs to, so the endpoint
    // does not hand it out again.
    expect($body['data']['providers']['meta'])->not->toHaveKey('redirect_uri')
        ->and($body['data']['providers']['linkedin'])->not->toHaveKey('redirect_uri');
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

    $response = postController($auth)->createPost(jsonRequest('POST', '/api/v1/plugins/social/posts', [
        'body'        => 'We shipped…',
        'account_ids' => [$account->id],
    ]));

    expect($response->getStatusCode())->toBe(422)
        ->and(decode($response)['error']['code'])->toBe('VALIDATION_ERROR');
});

it('creates a post and answers with the post', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $response = postController($auth)->createPost(jsonRequest('POST', '/api/v1/plugins/social/posts', [
        'principal_id' => $principalId,
        'body'         => 'We shipped…',
        'account_ids'  => [$account->id],
    ]));

    expect($response->getStatusCode())->toBe(201);

    $body = decode($response);
    expect($body['data']['status'])->toBe('draft')
        ->and($body['data']['body'])->toBe('We shipped…')
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

    $created = decode($controller->createPost(jsonRequest('POST', '/api/v1/plugins/social/posts', [
        'principal_id' => $principalId,
        'body'         => 'We shipped…',
        'account_ids'  => [$account->id],
    ])));

    $postId = $created['data']['id'];

    $publish = jsonRequest('POST', '/api/v1/plugins/social/posts/' . $postId . '/publish', [
        'principal_id' => $principalId,
    ]);
    $publish->attributes->set('id', $postId);

    $controller->publishPost($publish);

    $request = jsonRequest(
        'DELETE',
        '/api/v1/plugins/social/posts/' . $postId . '/published?principal_id=' . $principalId,
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

    $controller->createPost(jsonRequest('POST', '/api/v1/plugins/social/posts', [
        'principal_id' => $principalId,
        'body'         => 'We shipped…',
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

it('checks the fit of an asset and names the remedy', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/png', 'payload' => pngBytes()]);

    $response = mediaController($auth)->checkFit(jsonRequest('POST', '/api/v1/plugins/social/media/check-fit', [
        'principal_id'   => $principalId,
        'media_asset_id' => $asset->id,
        'platforms'      => ['instagram'],
    ]));

    expect($response->getStatusCode())->toBe(200);

    $body = decode($response);
    expect($body['data']['fits'])->toBeFalse()
        ->and($body['data']['per_platform'][0]['remedy'])->toBe('Convert to JPEG');
});

it('answers with the month of the calendar', function () {
    $auth = bootAuthLayer();
    $principalId = createUserPrincipal(bootAuth($auth));

    $response = calendarController($auth)->calendar(
        jsonRequest('GET', '/api/v1/plugins/social/calendar?principal_id=' . $principalId . '&month=2026-11'),
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

    expect($rules)->toHaveKeys(['linkedin', 'facebook', 'instagram']);

    foreach (SocialPlatform::cases() as $platform) {
        $rule = PlatformFit::rule($platform);

        expect($rules[$platform->value]['media_required'])->toBe($rule['media_required'])
            ->and($rules[$platform->value]['media_count'])->toBe($rule['media_count'])
            ->and($rules[$platform->value]['fetches_media'])->toBe($rule['fetches_media'])
            ->and($rules[$platform->value]['mime_types'])->toBe($rule['mime_types'])
            ->and($rules[$platform->value]['max_bytes'])->toBe($rule['max_bytes'])
            ->and($rules[$platform->value]['max_text'])->toBe($rule['max_characters'])
            ->and($rules[$platform->value]['media_note'])->toContain($rule['media'])
            ->and($rules[$platform->value])->toHaveKeys([
                'media_required',
                'media_count',
                'fetches_media',
                'mime_types',
                'max_bytes',
                'max_text',
                'media_note',
                'media_remedy',
                'video_remedy',
                'size_remedy',
            ]);
    }
});
