<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Publish\LinkedInAdapter;
use Spora\Plugins\Social\Publish\PlatformHttpClient;
use Spora\Plugins\Social\Publish\PublishRequest;
use Spora\Plugins\Social\Service\SocialMetricsService;
use Spora\Plugins\Social\Tests\Live\Support\LiveCheck;
use Spora\Plugins\Social\Tests\Live\Support\LiveContext;
use Spora\Plugins\Social\Tests\Live\Support\LiveContract;
use Spora\Plugins\Social\Tests\Live\Support\LiveTarget;

it('builds an authorization url with the client id, the member scope and a pkce challenge', function () {
    LiveContext::instance()
        ->check(SocialPlatform::LinkedIn, 'the authorization url carries the client id, the member scope and a pkce challenge')
        ->run(function () {
            $context = LiveContext::instance();
            $provider = $context->provider(SocialPlatform::LinkedIn);

            $verifier = str_repeat('v', 64);
            $url = $provider->authorizationUrl('live-check-state', $verifier);

            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $scopes = preg_split('/\s+/', (string) ($query['scope'] ?? '')) ?: [];

            LiveContract::holds(
                parse_url($url, PHP_URL_HOST) === 'www.linkedin.com',
                'the authorization url is not on www.linkedin.com',
            );
            LiveContract::holds(
                parse_url($url, PHP_URL_PATH) === '/oauth/v2/authorization',
                'the authorization url is not on the /oauth/v2/authorization path',
            );
            LiveContract::holds(
                ($query['client_id'] ?? null) === $context->credentials->clientId(SocialPlatform::LinkedIn),
                'the authorization url does not carry the configured client id',
            );
            LiveContract::holds(
                ($query['redirect_uri'] ?? null) === $context->redirectUri(SocialPlatform::LinkedIn),
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
                in_array('w_member_social', $scopes, true),
                'the authorization url does not ask for w_member_social, which is what member posting needs',
            );
            LiveContract::holds(
                in_array('openid', $scopes, true) && in_array('profile', $scopes, true),
                'the authorization url does not ask for the scopes the profile read needs',
            );
            LiveContract::holds(
                ($query['state'] ?? '') !== '',
                'the authorization url carries no state',
            );
        });
});

it('exchanges the authorization code for a token that expires', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::LinkedIn,
            'the code buys a token with an expiry',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION,
        )
        ->run(function () {
            $tokens = LiveContext::instance()->exchange(SocialPlatform::LinkedIn);

            LiveContract::holds($tokens->accessToken !== '', 'the token exchange returned no access token');
            LiveContract::holds($tokens->expiresAt !== null, 'the token exchange returned no expiry');
            LiveContract::holds(
                $tokens->expiresAt !== null && $tokens->expiresAt->getTimestamp() > time(),
                'the token is already expired',
            );
            LiveContract::holds(
                in_array('w_member_social', $tokens->scopes, true),
                'the platform did not grant w_member_social',
            );
        });
});

it('reads the member profile and gets a person urn to publish as', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::LinkedIn,
            'the profile read returns a person urn',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $accounts = $context->accounts(SocialPlatform::LinkedIn);
            $account = $accounts[0] ?? null;

            LiveContract::holds($account !== null, 'the profile read returned no account');
            LiveContract::holds(
                $account->kind === SocialAccountKind::Member,
                'the linkedin account is not a member account',
            );
            LiveContract::holds(
                str_starts_with($account->externalId, 'urn:li:person:'),
                'the member account carries no person urn',
            );
            LiveContract::holds(
                strlen($account->externalId) > strlen('urn:li:person:'),
                'the person urn carries no member id',
            );
            LiveContract::holds(
                ($account->extra['author_urn'] ?? null) === $account->externalId,
                'the account does not carry the author urn the post names',
            );

            $call = $context->oauthRecorder(SocialPlatform::LinkedIn)->last('GET', '/v2/userinfo');

            LiveContract::holds($call !== null, 'the profile read did not go to the linkedin userinfo endpoint');
            LiveContract::holds(
                str_starts_with((string) $call['url'], 'https://api.linkedin.com/'),
                'the profile read did not go to api.linkedin.com',
            );
            LiveContract::holds($call['status'] >= 200 && $call['status'] < 300, 'the userinfo endpoint answered with http ' . $call['status']);
        });
});

it('publishes a post under the member urn', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::LinkedIn,
            'a post goes out under the member urn',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $account = $context->accounts(SocialPlatform::LinkedIn)[0] ?? null;

            LiveContract::holds($account !== null, 'there is no member account to publish as');

            $recorder = $context->http(SocialPlatform::LinkedIn);
            $adapter = new LinkedInAdapter(new Spora\Plugins\Social\Publish\PlatformApi(
                new PlatformHttpClient($recorder),
                $context->versions(),
            ));

            $result = $adapter->publish(new PublishRequest(
                externalId: $account->externalId,
                kind: SocialAccountKind::Member,
                accessToken: $context->tokens(SocialPlatform::LinkedIn)->accessToken,
                body: 'Live check from Spora — this post is taken down again straight away.',
            ));

            $call = $recorder->last('POST', '/rest/posts');

            // The id is remembered even when the adapter called the publish a
            // failure: a post that went out has to be taken down again.
            $restliId = $call['headers']['x-restli-id'] ?? '';

            if ($restliId !== '') {
                $context->rememberPublished(SocialPlatform::LinkedIn, $restliId);
            }

            LiveContract::holds($result->success, 'linkedin refused the post: ' . ($result->error ?? 'no reason given'));
            LiveContract::holds(
                $result->externalId !== null && preg_match('/^urn:li:share:/', $result->externalId) === 1,
                'the published post carries no share urn',
            );
            LiveContract::holds(
                $result->externalUrl === 'https://www.linkedin.com/feed/update/' . $result->externalId,
                'the external url is not the documented follow url',
            );
            LiveContract::holds($call !== null, 'the post did not go to the posts endpoint');
            LiveContract::holds(
                str_starts_with((string) $call['url'], 'https://api.linkedin.com/rest/posts'),
                'the post did not go to api.linkedin.com',
            );
            LiveContract::holds(
                $call['status'] >= 200 && $call['status'] < 300,
                'the posts endpoint answered with http ' . $call['status'],
            );
            LiveContract::holds(
                $restliId !== '',
                'the posts endpoint returned no x-restli-id header',
            );
            LiveContract::holds(
                $restliId === $result->externalId,
                'the id the plugin recorded is not the id in the x-restli-id header',
            );
        });
});

it('sends the headers the posts endpoint needs', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::LinkedIn,
            'the publish request carries the headers linkedin needs',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN | LiveCheck::PUBLISHED,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $call = $context->http(SocialPlatform::LinkedIn)->last('POST', '/rest/posts');

            LiveContract::holds($call !== null, 'the publish request was not recorded');
            LiveContract::holds(
                isset($call['headers']['x-restli-protocol-version']),
                'the publish request carried no X-Restli-Protocol-Version header, which the rest.li protocol needs',
            );
            LiveContract::holds(
                isset($call['headers']['linkedin-version']),
                'the publish request carried no Linkedin-Version header, which every linkedin api asks for',
            );
            LiveContract::holds(
                str_starts_with((string) ($call['headers']['authorization'] ?? ''), 'Bearer '),
                'the publish request carried no bearer authorization header',
            );
        });
});

it('takes the post down again', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::LinkedIn,
            'the post is deleted again',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN | LiveCheck::PUBLISHED,
        )
        ->run(function () {
            $context = LiveContext::instance();
            $postId = (string) $context->published(SocialPlatform::LinkedIn);

            $response = $context->http(SocialPlatform::LinkedIn)->request(
                'DELETE',
                'https://api.linkedin.com/rest/posts/' . rawurlencode($postId),
                [
                    'headers' => $context->versions()->linkedInRestHeaders(
                        $context->tokens(SocialPlatform::LinkedIn)->accessToken,
                    ),
                ],
            );

            LiveContract::holds(
                $response->getStatusCode() === 204,
                'deleting the post answered with http ' . $response->getStatusCode() . ' instead of the documented 204',
            );
        });
});

it('reads the reactions of the member post back', function () {
    LiveContext::instance()
        ->check(
            SocialPlatform::LinkedIn,
            'the member post is readable through the insights api',
            LiveCheck::CREDENTIALS | LiveCheck::AUTHORIZATION | LiveCheck::TOKEN | LiveCheck::PUBLISHED,
        )
        ->run(function () {
            $context = LiveContext::instance();

            $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

            $target = LiveTarget::create(
                $context->security(),
                $principalId,
                SocialPlatform::LinkedIn,
                SocialAccountKind::Member,
                (string) $context->published(SocialPlatform::LinkedIn),
                $context->tokens(SocialPlatform::LinkedIn)->accessToken,
            );

            $service = new SocialMetricsService(
                new PlatformHttpClient($context->http(SocialPlatform::LinkedIn)),
                $context->accountService(),
                $context->versions(),
            );

            $row = $service->collect($target);

            LiveContract::holds($row !== null, 'the insights read stored no reading at all');

            $call = $context->http(SocialPlatform::LinkedIn)->last('GET', '/rest/socialActions/');

            LiveContract::holds($call !== null, 'the reactions were not read from the social actions endpoint');
            LiveContract::holds(
                str_starts_with((string) $call['url'], 'https://api.linkedin.com/rest/socialActions/'),
                'the reactions did not come from api.linkedin.com',
            );
            LiveContract::holds(
                $call['status'] >= 200 && $call['status'] < 300,
                'the social actions endpoint answered with http ' . $call['status'],
            );
            LiveContract::holds(
                isset($call['headers']['x-restli-protocol-version']),
                'the reactions read carried no X-Restli-Protocol-Version header, which the rest.li protocol needs',
            );
            LiveContract::holds(
                isset($call['headers']['linkedin-version']),
                'the reactions read carried no Linkedin-Version header, which every linkedin api asks for',
            );
        });
});
