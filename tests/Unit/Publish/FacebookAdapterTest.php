<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Publish\DeleteRequest;
use Spora\Plugins\Social\Publish\FacebookAdapter;
use Spora\Plugins\Social\Publish\PublishMedia;
use Spora\Plugins\Social\Publish\PublishRequest;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The recorded wire fixtures of Facebook's Page publishing.
 *
 * Every expectation below is transcribed from the documentation, with the
 * URL it came from.
 *
 * @see https://developers.facebook.com/docs/pages-api/posts
 *
 * @param list<array{body: string, status?: int}> $responses
 * @param list<array{method: string, url: string, options: array<string, mixed>}> $seen
 */
function facebookAdapter(array $responses, array &$seen): FacebookAdapter
{
    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen, $responses) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        $response = $responses[count($seen) - 1] ?? ['body' => '{}'];

        return new MockResponse($response['body'], ['http_code' => $response['status'] ?? 200]);
    });

    return new FacebookAdapter(platformApi($http));
}

/**
 * @param array<string, mixed> $options
 * @return array<string, string>
 */
function formFields(array $options): array
{
    parse_str((string) ($options['body'] ?? ''), $fields);

    /** @var array<string, string> $fields */
    return $fields;
}

it('publishes a text post to the page feed with the configured graph version', function () {
    $seen = [];

    // Page API → "Publishing":
    //   POST /{page-id}/feed
    //   message, published, access_token
    //   → { "id": "{page-id}_{post-id}" }
    $adapter = facebookAdapter([['body' => '{"id":"123456_789"}']], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: SocialFixtures::FACEBOOK_PAGE_ID,
        kind: SocialAccountKind::Page,
        accessToken: 'PAGE_TOKEN',
        body: SocialFixtures::POST_BODY,
    ));

    expect($result->success)->toBeTrue()
        ->and($result->externalId)->toBe(SocialFixtures::FACEBOOK_POST_ID)
        ->and($result->externalUrl)->toBe('https://www.facebook.com/123456_789');

    expect($seen)->toHaveCount(1);

    $request = $seen[0];
    expect($request['method'])->toBe('POST')
        ->and($request['url'])->toBe('https://graph.facebook.com/v26.0/123456/feed');

    $fields = formFields($request['options']);
    expect($fields['message'])->toBe(SocialFixtures::POST_BODY)
        ->and($fields['published'])->toBe('true')
        ->and($fields['access_token'])->toBe('PAGE_TOKEN');
});

it('publishes an image to the photos endpoint as a multipart upload', function () {
    $seen = [];

    $adapter = facebookAdapter([['body' => '{"id":"123456_790","post_id":"123456_790"}']], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: SocialFixtures::FACEBOOK_PAGE_ID,
        kind: SocialAccountKind::Page,
        accessToken: 'PAGE_TOKEN',
        body: 'Look at this',
        media: new PublishMedia(mimeType: 'image/png', bytes: 'PNGBYTES', filename: 'chart.png'),
    ));

    expect($result->success)->toBeTrue()
        ->and($result->externalId)->toBe('123456_790');

    $request = $seen[0];
    expect($request['url'])->toBe('https://graph.facebook.com/v26.0/123456/photos');

    $body = (string) $request['options']['body'];
    expect($body)->toContain('name="message"')
        ->and($body)->toContain('Look at this')
        ->and($body)->toContain('name="source"; filename="chart.png"')
        ->and($body)->toContain('PNGBYTES');
});

it('deletes the page post with the documented request', function () {
    $seen = [];

    // Pages API → "Delete a Post":
    //   DELETE https://graph.facebook.com/v26.0/page_post_id
    //   → { "success": true }
    $adapter = facebookAdapter([['body' => '{"success":true}']], $seen);

    $result = $adapter->delete(new DeleteRequest(
        externalId: SocialFixtures::FACEBOOK_POST_ID,
        kind: SocialAccountKind::Page,
        accessToken: 'PAGE_TOKEN',
    ));

    expect($result->success)->toBeTrue();
    expect($seen)->toHaveCount(1);

    $request = $seen[0];
    expect($request['method'])->toBe('DELETE')
        ->and($request['url'])->toStartWith('https://graph.facebook.com/v26.0/123456_789')
        ->and($request['url'])->toContain('access_token=PAGE_TOKEN');
});

it('reports the platform error when the delete is refused', function () {
    $seen = [];

    $adapter = facebookAdapter([[
        'body' => '{"error":{"message":"(#100) Unsupported delete request","type":"OAuthException"}}',
        'status' => 400,
    ]], $seen);

    $result = $adapter->delete(new DeleteRequest(
        externalId: SocialFixtures::FACEBOOK_POST_ID,
        kind: SocialAccountKind::Page,
        accessToken: 'PAGE_TOKEN',
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('(#100) Unsupported delete request');
});

it('reports the platform error when the page refuses the post', function () {
    $seen = [];

    $adapter = facebookAdapter([[
        'body' => '{"error":{"message":"(#200) Missing permission","type":"OAuthException"}}',
        'status' => 400,
    ]], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: SocialFixtures::FACEBOOK_PAGE_ID,
        kind: SocialAccountKind::Page,
        accessToken: 'PAGE_TOKEN',
        body: SocialFixtures::POST_BODY,
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('(#200) Missing permission');
});

it('publishes a video to the page', function () {
    $seen = [];

    $adapter = facebookAdapter([['body' => '{"id":"the-video"}']], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: 'the-page',
        kind: SocialAccountKind::Page,
        accessToken: 'PAGE_TOKEN',
        body: SocialFixtures::POST_BODY,
        media: new PublishMedia(
            mimeType: 'video/mp4',
            publicUrl: 'https://www.example.com/videos/clip.mp4',
        ),
    ));

    expect($result->success)->toBeTrue()
        ->and($seen[0]['url'])->toContain('/the-page/videos');
});

it('refuses a video the platform cannot fetch', function () {
    $seen = [];
    $adapter = facebookAdapter([['body' => '{}']], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: 'the-page',
        kind: SocialAccountKind::Page,
        accessToken: 'PAGE_TOKEN',
        body: SocialFixtures::POST_BODY,
        media: new PublishMedia(mimeType: 'video/mp4'),
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('publicly reachable URL');
});

it('says that the deletion was not confirmed', function () {
    $seen = [];
    $adapter = facebookAdapter([['body' => '{"success":false}']], $seen);

    $result = $adapter->delete(new DeleteRequest(
        externalId: 'the-post',
        kind: SocialAccountKind::Page,
        accessToken: 'PAGE_TOKEN',
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('did not confirm the deletion');
});
