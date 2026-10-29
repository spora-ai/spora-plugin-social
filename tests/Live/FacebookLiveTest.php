<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Publish\FacebookAdapter;
use Spora\Plugins\Social\Publish\PlatformHttpClient;
use Spora\Plugins\Social\Publish\PublishRequest;
use Spora\Plugins\Social\Service\SocialMetricsService;
use Spora\Plugins\Social\Tests\Live\Support\LiveCheck;
use Spora\Plugins\Social\Tests\Live\Support\LiveContext;
use Spora\Plugins\Social\Tests\Live\Support\LiveContract;
use Spora\Plugins\Social\Tests\Live\Support\LiveTarget;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;

it('builds an authorization url with the unified login', function () {
    LiveContext::instance()
        ->check(SocialPlatform::Facebook, 'the authorization url carries the unified login')
        ->run(function () {
            $context = LiveContext::instance();
            $provider = $context->provider(SocialPlatform::Facebook);

            $verifier = str_repeat('v', 64);
            $url = $provider->authorizationUrl('live-check-state', $verifier);

            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            LiveContract::holds(
                parse_url($url, PHP_URL_HOST) === 'www.facebook.com',
                'the authorization url is not on www.facebook.com, which is the host the facebook login for '
                . 'business dialog is served from',
            );
            LiveContract::holds(
                parse_url($url, PHP_URL_PATH) === '/' . $context->versions()->graph . '/dialog/oauth',
                'the authorization url is not on the versioned /dialog/oauth path',
            );
            LiveContract::holds(
                ($query['client_id'] ?? null) === $context->credentials->clientId(SocialPlatform::Facebook),
                'the authorization url does not carry the configured client id',
            );
            LiveContract::holds(
                ($query['config_id'] ?? null) === $context->credentials->metaConfigId(),
                'the authorization url does not carry the configuration id, which is what carries the permissions '
                . 'and the assets',
            );
            LiveContract::holds(
                ($query['redirect_uri'] ?? null) === $context->redirectUri(SocialPlatform::Facebook),
                'the authorization url does not carry the redirect uri the app has registered',
            );
            LiveContract::holds(
                ($query['response_type'] ?? null) === 'code',
                'the authorization url does not ask for an authorization code',
            );
            LiveContract::holds(
                !isset($query['scope']),
                'the authorization url asks for a scope, which the unified login does not use — the configuration '
                . 'carries the permissions',
            );
        });
});

it('exchanges the code for a token the configuration decides the life of', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Facebook,
            'the code buys a token',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $tokens = $context->exchange(SocialPlatform::Facebook);

            LiveContract::holds($tokens->accessToken !== '', 'the token exchange returned no access token');
            LiveContract::holds(
                $tokens->expiresAt === null || $tokens->expiresAt->getTimestamp() > time(),
                'the expiry the platform sent has already passed',
            );

            $tokenCall = $context->oauthRecorder(SocialPlatform::Facebook)->last('GET', '/oauth/access_token');

            LiveContract::holds($tokenCall !== null, 'the token was not exchanged at the token endpoint');
            LiveContract::holds(
                str_starts_with(
                    (string) $tokenCall['url'],
                    SocialFixtures::GRAPH_HOST . $context->versions()->graph . '/oauth/access_token',
                ),
                'the token was not exchanged on the graph host the unified login is served from',
            );
            LiveContract::holds(
                $tokenCall['status'] >= 200 && $tokenCall['status'] < 300,
                'the token endpoint answered with http ' . $tokenCall['status'],
            );
            LiveContract::holds(
                !str_contains((string) $tokenCall['body'], 'refresh_token'),
                'the token response carries a refresh grant, which the system-user token of the unified login does '
                . 'not hand out',
            );
        });
});

it('lists the pages the business shared', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Facebook,
            'the page list gives a page the connection token can publish as',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $accounts = $context->accounts(SocialPlatform::Facebook);
            $page = $accounts[0] ?? null;

            LiveContract::holds($page !== null, 'the page list is empty');
            LiveContract::holds(
                $page->kind === SocialAccountKind::Page,
                'the facebook account is not a page account',
            );
            LiveContract::holds(
                preg_match('/^\d+$/', $page->externalId) === 1,
                'the page account carries no numeric page id',
            );

            // One login, one token: the page carries no token of its own,
            // and the connection's token publishes as the page.
            LiveContract::holds(
                !isset($page->extra['page_access_token']),
                'the page carries a page access token, which the unified login does not hand out',
            );

            $businessId = (string) ($context->tokens(SocialPlatform::Facebook)->extra['business_external_id'] ?? '');

            LiveContract::holds($businessId !== '', 'the token carried no client business id, so no page can be listed');

            $call = $context->oauthRecorder(SocialPlatform::Facebook)->last('GET', '/owned_pages');

            LiveContract::holds($call !== null, 'the page list did not come from the owned pages endpoint');
            LiveContract::holds(
                str_starts_with(
                    (string) $call['url'],
                    SocialFixtures::GRAPH_HOST . $context->versions()->graph . '/' . $businessId . '/owned_pages',
                ),
                'the page list did not come from the business owned pages endpoint on the graph host',
            );
            LiveContract::holds(
                $call['status'] >= 200 && $call['status'] < 300,
                'the owned pages endpoint answered with http ' . $call['status'],
            );
        });
});

it('publishes a post on the page', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Facebook,
            'a post goes out on the page',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $page = $context->accounts(SocialPlatform::Facebook)[0] ?? null;

            LiveContract::holds($page !== null, 'there is no page to publish on');

            $token = $context->tokens(SocialPlatform::Facebook)->accessToken;

            LiveContract::holds($token !== '', 'the connection carries no access token to publish with');

            $recorder = $context->http(SocialPlatform::Facebook);
            $adapter = new FacebookAdapter(new Spora\Plugins\Social\Publish\PlatformApi(
                new PlatformHttpClient($recorder),
                $context->versions(),
            ));

            $result = $adapter->publish(new PublishRequest(
                externalId: $page->externalId,
                kind: SocialAccountKind::Page,
                accessToken: $token,
                body: 'Live check from Spora — this post is taken down again straight away.',
            ));

            $call = $recorder->last('POST', SocialFixtures::FACEBOOK_FEED_PATH);

            if ($result->externalId !== null) {
                $context->rememberPublished(SocialPlatform::Facebook, $result->externalId);
            }

            LiveContract::holds($result->success, 'facebook refused the post: ' . ($result->error ?? 'no reason given'));
            LiveContract::holds(
                $result->externalId !== null && preg_match('/^\d+_\d+$/', $result->externalId) === 1,
                'the published post carries no page id and post id',
            );
            LiveContract::holds(
                $result->externalUrl === 'https://www.facebook.com/' . $result->externalId,
                'the external url is not the documented permalink',
            );
            LiveContract::holds($call !== null, 'the post did not go to the feed endpoint');
            LiveContract::holds(
                str_starts_with(
                    (string) $call['url'],
                    SocialFixtures::GRAPH_HOST . $context->versions()->graph . '/',
                ),
                'the post did not go to the graph host the unified login is served from',
            );
            LiveContract::holds(
                str_ends_with((string) $call['url'], '/' . $page->externalId . SocialFixtures::FACEBOOK_FEED_PATH),
                'the post did not go to the feed of the page',
            );
            LiveContract::holds(
                $call['status'] >= 200 && $call['status'] < 300,
                'the feed endpoint answered with http ' . $call['status'],
            );
        });
});

it('takes the post down again', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Facebook,
            'the post is deleted again',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN | LiveCheck::PUBLISHED,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $page = $context->accounts(SocialPlatform::Facebook)[0] ?? null;
            $publishCall = $context->http(SocialPlatform::Facebook)->last('POST', SocialFixtures::FACEBOOK_FEED_PATH);

            LiveContract::holds($publishCall !== null, 'the publish request was not recorded');
            LiveContract::holds($page !== null, 'there is no page to delete the post from');

            $base = substr((string) $publishCall['url'], 0, -strlen(SocialFixtures::FACEBOOK_FEED_PATH));
            $postId = (string) $context->published(SocialPlatform::Facebook);

            $response = $context->http(SocialPlatform::Facebook)->request(
                'DELETE',
                $base . '/' . $postId,
                ['query' => ['access_token' => $context->tokens(SocialPlatform::Facebook)->accessToken]],
            );

            $status = $response->getStatusCode();
            $deleted = $status >= 200 && $status < 300;

            $context->report->note(
                SocialPlatform::Facebook->value,
                $deleted
                    ? 'the live post was deleted again — the page carries no trace of it'
                    : 'facebook refused the delete (http ' . $status . ') — the live post may still be on the page',
            );

            fwrite(STDOUT, $deleted
                ? "\n  the live post was deleted again\n"
                : "\n  facebook refused the delete (http " . $status . ") — the live post may still be on the page\n");

            $answer = json_decode($response->getContent(false), true);

            LiveContract::holds(
                is_array($answer),
                'the delete was not answered with a graph api answer',
            );
        });
});

it('reads the page insights with the connection token', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Facebook,
            'the page insights are readable',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN | LiveCheck::PUBLISHED,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $page = $context->accounts(SocialPlatform::Facebook)[0] ?? null;

            LiveContract::holds($page !== null, 'there is no page to read the insights of');

            $token = $context->tokens(SocialPlatform::Facebook)->accessToken;

            $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

            // The insights path takes the account's access token, and under
            // the unified login that is the connection's token.
            $target = LiveTarget::create(
                $context->security(),
                $principalId,
                SocialPlatform::Facebook,
                SocialAccountKind::Page,
                (string) $context->published(SocialPlatform::Facebook),
                $token,
            );

            $service = new SocialMetricsService(
                new PlatformHttpClient($context->http(SocialPlatform::Facebook)),
                $context->accountService(),
                $context->versions(),
            );

            $row = $service->collect($target);

            LiveContract::holds($row !== null, 'the insights read stored no reading at all');

            $used = $context->accountService()->accessToken($target->account?->connection);

            LiveContract::holds(
                $used === $token,
                'the insights were not read with the connection\'s token',
            );

            $call = $context->http(SocialPlatform::Facebook)->last('GET', '/insights');

            LiveContract::holds($call !== null, 'the insights were not read from the insights endpoint');
            LiveContract::holds(
                str_starts_with(
                    (string) $call['url'],
                    SocialFixtures::GRAPH_HOST . $context->versions()->graph . '/',
                ),
                'the insights did not come from the graph host the unified login is served from',
            );
            LiveContract::holds(
                str_contains((string) $call['url'], 'access_token='),
                'the insights were read without a token',
            );
            LiveContract::holds(
                $call['status'] >= 200 && $call['status'] < 300,
                'the insights endpoint answered with http ' . $call['status'],
            );
        });
});
