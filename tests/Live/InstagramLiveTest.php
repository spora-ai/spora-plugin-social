<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Publish\InstagramAdapter;
use Spora\Plugins\Social\Publish\PlatformHttpClient;
use Spora\Plugins\Social\Publish\PublishMedia;
use Spora\Plugins\Social\Publish\PublishRequest;
use Spora\Plugins\Social\Service\SocialMetricsService;
use Spora\Plugins\Social\Tests\Live\Support\LiveCheck;
use Spora\Plugins\Social\Tests\Live\Support\LiveContext;
use Spora\Plugins\Social\Tests\Live\Support\LiveContract;
use Spora\Plugins\Social\Tests\Live\Support\LiveTarget;
use Symfony\Component\HttpClient\HttpClient;

it('builds an authorization url with the instagram login scopes', function () {
    LiveContext::instance()
        ->check(SocialPlatform::Instagram, 'the authorization url carries the instagram login scopes')
        ->run(function () {
            $context = LiveContext::instance();
            $provider = $context->provider(SocialPlatform::Instagram);

            $verifier = str_repeat('v', 64);
            $url = $provider->authorizationUrl('live-check-state', $verifier);

            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $scopes = array_filter(array_map('trim', explode(',', (string) ($query['scope'] ?? ''))));

            LiveContract::holds(
                parse_url($url, PHP_URL_HOST) === 'www.instagram.com',
                'the authorization url is not on www.instagram.com',
            );
            LiveContract::holds(
                parse_url($url, PHP_URL_PATH) === '/oauth/authorize',
                'the authorization url is not on the /oauth/authorize path',
            );
            LiveContract::holds(
                ($query['client_id'] ?? null) === $context->credentials->clientId(SocialPlatform::Instagram),
                'the authorization url does not carry the configured client id',
            );
            LiveContract::holds(
                ($query['redirect_uri'] ?? null) === $context->redirectUri(SocialPlatform::Instagram),
                'the authorization url does not carry the redirect uri the app has registered',
            );
            LiveContract::holds(
                ($query['response_type'] ?? null) === 'code',
                'the authorization url does not ask for an authorization code',
            );
            LiveContract::holds(
                ($query['code_challenge_method'] ?? null) === 'S256',
                'the authorization url does not ask for the S256 pkce challenge',
            );
            LiveContract::holds(
                ($query['code_challenge'] ?? null) === LiveContract::pkceChallenge($verifier),
                'the pkce challenge is not the challenge of the verifier the exchange will present',
            );
            LiveContract::holds(
                in_array('instagram_business_basic', $scopes, true),
                'the authorization url does not ask for instagram_business_basic, which is how the account is read',
            );
            LiveContract::holds(
                in_array('instagram_business_content_publish', $scopes, true),
                'the authorization url does not ask for instagram_business_content_publish, which is how a post goes out',
            );
        });
});

it('exchanges the code and reads the instagram account behind it', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Instagram,
            'the code buys a token and the profile read gives an instagram id',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $tokens = $context->exchange(SocialPlatform::Instagram);

            LiveContract::holds($tokens->accessToken !== '', 'the token exchange returned no access token');
            LiveContract::holds($tokens->expiresAt !== null, 'the token exchange returned no expiry');
            LiveContract::holds(
                $tokens->expiresAt !== null && $tokens->expiresAt->getTimestamp() > time(),
                'the token is already expired',
            );
            LiveContract::holds(
                $tokens->refreshToken !== null && $tokens->refreshToken !== '',
                'instagram handed out no refresh token',
            );

            $tokenCall = $context->oauthRecorder(SocialPlatform::Instagram)->last('POST', '/oauth/access_token');

            LiveContract::holds($tokenCall !== null, 'the token was not exchanged at the token endpoint');
            LiveContract::holds(
                str_starts_with((string) $tokenCall['url'], 'https://api.instagram.com/'),
                'the token was not exchanged on api.instagram.com',
            );
            LiveContract::holds(
                $tokenCall['status'] >= 200 && $tokenCall['status'] < 300,
                'the token endpoint answered with http ' . $tokenCall['status'],
            );

            $accounts = $context->accounts(SocialPlatform::Instagram);
            $account = $accounts[0] ?? null;

            LiveContract::holds($account !== null, 'the profile read returned no account');
            LiveContract::holds(
                preg_match('/^\d+$/', $account->externalId) === 1,
                'the instagram account carries no numeric instagram user id',
            );

            $profileCall = $context->oauthRecorder(SocialPlatform::Instagram)->last('GET', '/me');

            LiveContract::holds($profileCall !== null, 'the profile read did not happen');
            LiveContract::holds(
                str_starts_with((string) $profileCall['url'], 'https://graph.instagram.com/'),
                'the profile read did not go to graph.instagram.com, which is the host the instagram api with '
                . 'instagram login is served from',
            );
            LiveContract::holds(
                $profileCall['status'] >= 200 && $profileCall['status'] < 300,
                'the profile endpoint answered with http ' . $profileCall['status'],
            );
            LiveContract::holds(
                str_contains($profileCall['body'], $account->externalId),
                'the account id the plugin derived is not the id the platform answered with',
            );
        });
});

it('finds a publicly reachable jpeg to publish', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Instagram,
            'the public image is reachable and is a jpeg',
            LiveCheck::PUBLIC_IMAGE,
        )
        ->run(function () {
            $url = (string) LiveContext::instance()->credentials->publicImageUrl();

            $response = HttpClient::create(['timeout' => 30])->request('GET', $url);

            LiveContract::holds(
                $response->getStatusCode() === 200,
                'the public image url answered with http ' . $response->getStatusCode(),
            );

            $headers = $response->getHeaders(false);
            $contentType = strtolower((string) ($headers['content-type'][0] ?? ''));

            LiveContract::holds(
                str_contains($contentType, 'image/jpeg'),
                'the public image is not served as a jpeg — instagram takes no png',
            );

            $bytes = $response->getContent(false);

            LiveContract::holds(
                str_starts_with($bytes, "\xFF\xD8\xFF"),
                'the public image does not begin with the jpeg marker',
            );
        });
});

it('creates a container and publishes it', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Instagram,
            'a container is created and published',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN | LiveCheck::PUBLIC_IMAGE,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $account = $context->accounts(SocialPlatform::Instagram)[0] ?? null;

            LiveContract::holds($account !== null, 'there is no instagram account to publish as');

            $recorder = $context->http(SocialPlatform::Instagram);
            $adapter = new InstagramAdapter(new Spora\Plugins\Social\Publish\PlatformApi(
                new PlatformHttpClient($recorder),
                $context->versions(),
            ));

            $result = $adapter->publish(new PublishRequest(
                externalId: $account->externalId,
                kind: SocialAccountKind::Business,
                accessToken: $context->tokens(SocialPlatform::Instagram)->accessToken,
                body: 'Live check from Spora — the api cannot take this post down again.',
                media: new PublishMedia(
                    mimeType: 'image/jpeg',
                    publicUrl: (string) $context->credentials->publicImageUrl(),
                ),
            ));

            if ($result->externalId !== null) {
                $context->rememberPublished(SocialPlatform::Instagram, $result->externalId);
            }

            LiveContract::holds($result->success, 'instagram refused the publish: ' . ($result->error ?? 'no reason given'));
            LiveContract::holds(
                $result->externalId !== null && preg_match('/^\d+$/', $result->externalId) === 1,
                'the published media carries no numeric media id',
            );
            LiveContract::holds(
                $result->externalUrl === 'https://www.instagram.com/p/' . $result->externalId,
                'the external url is not the documented permalink',
            );

            $container = null;
            $publish = null;

            foreach ($recorder->calls() as $call) {
                if ($call['method'] !== 'POST') {
                    continue;
                }

                if (str_ends_with($call['url'], '/media_publish')) {
                    $publish = $call;
                } elseif (str_ends_with($call['url'], '/media')) {
                    $container = $call;
                }
            }

            LiveContract::holds($container !== null, 'no container was created');
            LiveContract::holds($publish !== null, 'the container was never published');

            LiveContract::holds(
                str_starts_with((string) $container['url'], 'https://graph.instagram.com/'),
                'the container was not created on graph.instagram.com, which is the host the instagram api with '
                . 'instagram login is served from',
            );
            LiveContract::holds(
                $container['status'] >= 200 && $container['status'] < 300,
                'the container endpoint answered with http ' . $container['status'],
            );
            LiveContract::holds(
                str_ends_with((string) $container['url'], '/' . $account->externalId . '/media'),
                'the container was not created on the instagram account',
            );

            $containerBody = json_decode($container['body'], true);
            $containerId = is_array($containerBody) ? ($containerBody['id'] ?? null) : null;

            LiveContract::holds(
                is_string($containerId) && $containerId !== '',
                'the container endpoint returned no container id',
            );

            LiveContract::holds(
                str_starts_with((string) $publish['url'], 'https://graph.instagram.com/'),
                'the container was not published on graph.instagram.com',
            );
            LiveContract::holds(
                str_ends_with((string) $publish['url'], '/' . $account->externalId . '/media_publish'),
                'the container was not published on the instagram account',
            );
            LiveContract::holds(
                $publish['status'] >= 200 && $publish['status'] < 300,
                'the publish endpoint answered with http ' . $publish['status'],
            );
            LiveContract::holds(
                str_contains((string) $publish['request'], (string) $containerId),
                'the published request did not name the container the plugin created',
            );

            $publishBody = json_decode($publish['body'], true);

            LiveContract::holds(
                is_array($publishBody) && ($publishBody['id'] ?? null) === $result->externalId,
                'the published media id is not the id the platform answered with',
            );
        });
});

it('reads the insights of the published media', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Instagram,
            'the insights of the published media are readable',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN | LiveCheck::PUBLISHED,
        )
        ->run(function () {
            $context = LiveContext::instance();

            $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

            $target = LiveTarget::create(
                $context->security(),
                $principalId,
                SocialPlatform::Instagram,
                SocialAccountKind::Business,
                (string) $context->published(SocialPlatform::Instagram),
                $context->tokens(SocialPlatform::Instagram)->accessToken,
            );

            $service = new SocialMetricsService(
                new PlatformHttpClient($context->http(SocialPlatform::Instagram)),
                $context->accountService(),
                $context->versions(),
            );

            $row = $service->collect($target);

            LiveContract::holds($row !== null, 'the insights read stored no reading at all');

            $call = $context->http(SocialPlatform::Instagram)->last('GET', '/insights');

            LiveContract::holds($call !== null, 'the insights were not read from the insights endpoint');
            LiveContract::holds(
                str_contains((string) $call['url'], (string) $context->published(SocialPlatform::Instagram)),
                'the insights were not read for the media that was published',
            );
            LiveContract::holds(
                $call['status'] >= 200 && $call['status'] < 300,
                'the insights endpoint answered with http ' . $call['status']
                . ' — a media that was published a moment ago sometimes has no numbers yet, so this can pass on a later run',
            );

            $body = json_decode($call['body'], true);

            LiveContract::holds(
                is_array($body) && array_key_exists('data', $body),
                'the insights answer does not carry the documented data list',
            );
        });
});
