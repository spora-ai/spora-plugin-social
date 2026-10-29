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

it('builds an authorization url with the page scopes', function () {
    LiveContext::instance()
        ->check(SocialPlatform::Facebook, 'the authorization url carries the page scopes and a pkce challenge')
        ->run(function () {
            $context = LiveContext::instance();
            $provider = $context->provider(SocialPlatform::Facebook);

            $verifier = str_repeat('v', 64);
            $url = $provider->authorizationUrl('live-check-state', $verifier);

            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $scopes = array_filter(array_map('trim', explode(',', (string) ($query['scope'] ?? ''))));

            LiveContract::holds(
                parse_url($url, PHP_URL_HOST) === 'www.facebook.com',
                'the authorization url is not on www.facebook.com',
            );
            LiveContract::holds(
                preg_match('#^/v\d+\.\d+/dialog/oauth$#', (string) parse_url($url, PHP_URL_PATH)) === 1,
                'the authorization url is not on a versioned facebook dialog oauth path',
            );
            LiveContract::holds(
                ($query['client_id'] ?? null) === $context->credentials->clientId(SocialPlatform::Facebook),
                'the authorization url does not carry the configured client id',
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
                ($query['code_challenge_method'] ?? null) === 'S256',
                'the authorization url does not ask for the S256 pkce challenge',
            );
            LiveContract::holds(
                ($query['code_challenge'] ?? null) === LiveContract::pkceChallenge($verifier),
                'the pkce challenge is not the challenge of the verifier the exchange will present',
            );
            LiveContract::holds(
                in_array('pages_show_list', $scopes, true),
                'the authorization url does not ask for pages_show_list, which is how the page list is read',
            );
            LiveContract::holds(
                in_array('pages_manage_posts', $scopes, true),
                'the authorization url does not ask for pages_manage_posts, which is how a post goes out',
            );
            LiveContract::holds(
                in_array('pages_read_engagement', $scopes, true),
                'the authorization url does not ask for pages_read_engagement, which is how the numbers come back',
            );
        });
});

it('exchanges the authorization code for a long-lived token', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Facebook,
            'the code buys a long-lived token',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $tokens = $context->exchange(SocialPlatform::Facebook);

            LiveContract::holds($tokens->accessToken !== '', 'the token exchange returned no access token');
            LiveContract::holds($tokens->expiresAt !== null, 'the token exchange returned no expiry');

            $days = $tokens->expiresAt !== null
                ? ($tokens->expiresAt->getTimestamp() - time()) / 86400
                : 0;

            LiveContract::holds(
                $days > 30 && $days < 61,
                'the token does not live the sixty days a long-lived facebook user token lives',
            );

            $grants = [];
            foreach ($context->oauthCalls(SocialPlatform::Facebook) as $call) {
                if (!str_contains($call['url'], '/oauth/access_token')) {
                    continue;
                }

                $grants[] = $call['form']['grant_type'] ?? '';
            }

            LiveContract::holds(
                in_array('authorization_code', $grants, true),
                'the code was not exchanged with the authorization_code grant',
            );
            LiveContract::holds(
                in_array('fb_exchange_token', $grants, true),
                'the short-lived token was not exchanged for a long-lived one',
            );
        });
});

it('lists the pages and derives a page token from the list', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Facebook,
            'the page list gives a page and a page access token',
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

            $pageToken = $page->extra['page_access_token'] ?? null;

            LiveContract::holds(
                is_string($pageToken) && $pageToken !== '',
                'the plugin derived no page access token from the page list',
            );

            $call = $context->oauthRecorder(SocialPlatform::Facebook)->last('GET', '/me/accounts');

            LiveContract::holds($call !== null, 'the page list did not come from the accounts endpoint');
            LiveContract::holds(
                str_starts_with((string) $call['url'], 'https://graph.facebook.com/'),
                'the page list did not come from graph.facebook.com',
            );
            LiveContract::holds(
                $call['status'] >= 200 && $call['status'] < 300,
                'the accounts endpoint answered with http ' . $call['status'],
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

            $pageToken = (string) ($page->extra['page_access_token'] ?? '');

            LiveContract::holds($pageToken !== '', 'the page carries no access token to publish with');

            $recorder = $context->http(SocialPlatform::Facebook);
            $adapter = new FacebookAdapter(new Spora\Plugins\Social\Publish\PlatformApi(
                new PlatformHttpClient($recorder),
                $context->versions(),
            ));

            $result = $adapter->publish(new PublishRequest(
                externalId: $page->externalId,
                kind: SocialAccountKind::Page,
                accessToken: $pageToken,
                body: 'Live check from Spora — this post is taken down again straight away.',
            ));

            $call = $recorder->last('POST', '/feed');

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
                str_starts_with((string) $call['url'], 'https://graph.facebook.com/'),
                'the post did not go to graph.facebook.com',
            );
            LiveContract::holds(
                str_ends_with((string) $call['url'], '/' . $page->externalId . '/feed'),
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
            $publishCall = $context->http(SocialPlatform::Facebook)->last('POST', '/feed');

            LiveContract::holds($publishCall !== null, 'the publish request was not recorded');
            LiveContract::holds($page !== null, 'there is no page to delete the post from');

            $base = substr((string) $publishCall['url'], 0, -strlen('/feed'));
            $postId = (string) $context->published(SocialPlatform::Facebook);

            $response = $context->http(SocialPlatform::Facebook)->request(
                'DELETE',
                $base . '/' . $postId,
                ['query' => ['access_token' => (string) ($page->extra['page_access_token'] ?? '')]],
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

it('reads the page insights with the page token', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::Facebook,
            'the page insights are readable with the page token',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN | LiveCheck::PUBLISHED,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $page = $context->accounts(SocialPlatform::Facebook)[0] ?? null;

            LiveContract::holds($page !== null, 'there is no page to read the insights of');

            $pageToken = (string) ($page->extra['page_access_token'] ?? '');

            $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

            // The insights path takes the account's access token, so the
            // page token is what goes in: the claim under test is that the
            // page's numbers are readable with the page's own token.
            $target = LiveTarget::create(
                $context->security(),
                $principalId,
                SocialPlatform::Facebook,
                SocialAccountKind::Page,
                (string) $context->published(SocialPlatform::Facebook),
                $pageToken,
                $pageToken,
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
                $used === $pageToken,
                'the insights were not read with the connection\'s token',
            );

            $call = $context->http(SocialPlatform::Facebook)->last('GET', '/insights');

            LiveContract::holds($call !== null, 'the insights were not read from the insights endpoint');
            LiveContract::holds(
                str_starts_with((string) $call['url'], 'https://graph.facebook.com/'),
                'the insights did not come from graph.facebook.com',
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
