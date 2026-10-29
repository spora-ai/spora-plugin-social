<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Publish\DeleteRequest;
use Spora\Plugins\Social\Publish\InstagramAdapter;
use Spora\Plugins\Social\Publish\PublishMedia;
use Spora\Plugins\Social\Publish\PublishRequest;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The recorded wire fixtures of Instagram's content publishing.
 *
 * Every expectation below is transcribed from the documentation, with the
 * URL it came from.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/content-publishing
 * @see https://developers.facebook.com/documentation/instagram-platform/instagram-graph-api/reference/ig-user/content_publishing_limit
 *
 * @param list<array{body: string, status?: int}> $responses
 * @param list<array{method: string, url: string, options: array<string, mixed>}> $seen
 */
function instagramAdapter(array $responses, array &$seen): InstagramAdapter
{
    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen, $responses) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        $response = $responses[count($seen) - 1] ?? ['body' => '{}'];

        return new MockResponse($response['body'], ['http_code' => $response['status'] ?? 200]);
    });

    return new InstagramAdapter(platformApi($http));
}

it('creates a container on the graph host and publishes it', function () {
    $seen = [];

    // Content publishing → "Create a container":
    //   POST "https://graph.facebook.com/<API_VERSION>/<IG_ID>/media"
    //   -H "Content-Type: application/json"
    //   -H "Authorization: Bearer <ACCESS_TOKEN>"
    //   -d '{ "image_url": SocialFixtures::INSTAGRAM_IMAGE_URL }'
    //   → { "id": "<IG_CONTAINER_ID>" }
    //
    // Content publishing → "Publish the container":
    //   POST "https://<HOST_URL>/<LATEST_API_VERSION>/<IG_ID>/media_publish"
    //   -H "Content-Type: application/json"
    //   -H "Authorization: Bearer <ACCESS_TOKEN>"
    //   -d '{ "creation_id": "<IG_CONTAINER_ID>" }'
    //   → { "id": "<IG_MEDIA_ID>" }
    $adapter = instagramAdapter([
        ['body' => '{"data":[{"quota_usage":2,"config":{"quota_total":50,"quota_duration":86400}}]}'],
        ['body' => SocialFixtures::INSTAGRAM_CONTAINER_RESPONSE],
        ['body' => '{"id":"17900000000000002"}'],
    ], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: SocialFixtures::INSTAGRAM_ACCOUNT_ID,
        kind: SocialAccountKind::Business,
        accessToken: 'ACCESS_TOKEN',
        body: SocialFixtures::INSTAGRAM_CAPTION,
        media: new PublishMedia(
            mimeType: SocialFixtures::MIME_JPEG,
            publicUrl: SocialFixtures::INSTAGRAM_IMAGE_URL,
            filename: 'bronz-fonz.jpg',
        ),
    ));

    expect($result->success)->toBeTrue()
        ->and($result->externalId)->toBe(SocialFixtures::INSTAGRAM_MEDIA_ID)
        ->and($result->externalUrl)->toBe('https://www.instagram.com/p/17900000000000002');

    expect($seen)->toHaveCount(3);

    expect($seen[1]['method'])->toBe('POST')
        ->and($seen[1]['url'])->toBe('https://graph.facebook.com/v26.0/17841405822304914/media');

    $containerHeaders = sentHeaders($seen[1]['options']);
    expect($containerHeaders['authorization'])->toBe('Bearer ACCESS_TOKEN')
        ->and($containerHeaders['content-type'])->toBe('application/json');

    expect(sentJson($seen[1]['options']))->toBe([
        'image_url' => SocialFixtures::INSTAGRAM_IMAGE_URL,
        'caption'   => SocialFixtures::INSTAGRAM_CAPTION,
    ]);

    expect($seen[2]['method'])->toBe('POST')
        ->and($seen[2]['url'])->toBe('https://graph.facebook.com/v26.0/17841405822304914/media_publish')
        ->and(sentJson($seen[2]['options']))->toBe(['creation_id' => '17900000000000001']);
});

it('names the video parameter the documentation names', function () {
    $seen = [];

    $adapter = instagramAdapter([
        ['body' => SocialFixtures::INSTAGRAM_QUOTA],
        ['body' => SocialFixtures::INSTAGRAM_CONTAINER_RESPONSE],
        ['body' => '{"id":"17900000000000002"}'],
    ], $seen);

    $adapter->publish(new PublishRequest(
        externalId: SocialFixtures::INSTAGRAM_ACCOUNT_ID,
        kind: SocialAccountKind::Business,
        accessToken: 'ACCESS_TOKEN',
        body: 'A clip',
        media: new PublishMedia(
            mimeType: 'video/mp4',
            publicUrl: 'https://www.example.com/videos/bronz-fonz.mp4',
            filename: 'bronz-fonz.mp4',
        ),
    ));

    expect(sentJson($seen[1]['options']))->toBe([
        'video_url' => 'https://www.example.com/videos/bronz-fonz.mp4',
        'caption'   => 'A clip',
    ]);
});

it('checks the publishing quota before it publishes', function () {
    $seen = [];

    // Content publishing → "Rate Limit": "Instagram accounts are limited
    // to 100 API-published posts within a 24-hour moving period... We
    // recommend that your app also enforce the publishing rate limit".
    //
    // content_publishing_limit → "Reading":
    //   GET https://graph.facebook.com/<API_VERSION>/<IG_USER_ID>/content_publishing_limit
    //     ?fields=<LIST_OF_FIELDS>&since=<UNIX_TIMESTAMP>&access_token=<ACCESS_TOKEN>
    //   → { "data": [ { "quota_usage": 2, "config": { "quota_total": 50, "quota_duration": 86400 } } ] }
    $adapter = instagramAdapter([
        ['body' => '{"data":[{"quota_usage":50,"config":{"quota_total":50,"quota_duration":86400}}]}'],
    ], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: SocialFixtures::INSTAGRAM_ACCOUNT_ID,
        kind: SocialAccountKind::Business,
        accessToken: 'ACCESS_TOKEN',
        body: SocialFixtures::INSTAGRAM_CAPTION,
        media: new PublishMedia(
            mimeType: SocialFixtures::MIME_JPEG,
            publicUrl: SocialFixtures::INSTAGRAM_IMAGE_URL,
            filename: 'bronz-fonz.jpg',
        ),
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('50 of its 50 posts')
        ->and($seen)->toHaveCount(1)
        ->and($seen[0]['method'])->toBe('GET')
        ->and($seen[0]['url'])->toContain('content_publishing_limit');
});

it('refuses a post without media, because instagram has no text-only post', function () {
    $seen = [];
    $adapter = instagramAdapter([['body' => '{}']], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: SocialFixtures::INSTAGRAM_ACCOUNT_ID,
        kind: SocialAccountKind::Business,
        accessToken: 'ACCESS_TOKEN',
        body: 'Just words',
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('without media')
        ->and($seen)->toHaveCount(0);
});

it('says that instagram cannot delete published media', function () {
    $seen = [];

    // The delete endpoint exists, but only for the Instagram API with
    // Facebook Login: "This api only supports Instagram API with Facebook
    // login only." The plugin speaks Instagram Login, so the endpoint is
    // not there for it.
    //
    // @see https://developers.facebook.com/documentation/instagram-platform/reference/instagram-media
    $adapter = instagramAdapter([['body' => '{}']], $seen);

    $result = $adapter->delete(new DeleteRequest(
        externalId: SocialFixtures::INSTAGRAM_MEDIA_ID,
        kind: SocialAccountKind::Business,
        accessToken: 'ACCESS_TOKEN',
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('cannot take a published post back down')
        ->and($result->error)->toContain('Instagram app')
        ->and($seen)->toHaveCount(0);
});

it('reports the platform error when the container is refused', function () {
    $seen = [];

    $adapter = instagramAdapter([
        ['body' => SocialFixtures::INSTAGRAM_QUOTA],
        ['body' => '{"error":{"message":"Media is not ready","code":190}}', 'status' => 400],
    ], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: SocialFixtures::INSTAGRAM_ACCOUNT_ID,
        kind: SocialAccountKind::Business,
        accessToken: 'ACCESS_TOKEN',
        body: SocialFixtures::INSTAGRAM_CAPTION,
        media: new PublishMedia(
            mimeType: SocialFixtures::MIME_JPEG,
            publicUrl: SocialFixtures::INSTAGRAM_IMAGE_URL,
            filename: 'bronz-fonz.jpg',
        ),
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Media is not ready');
});

it('accepts the published status a container carries once it has gone out', function () {
    $seen = [];

    // Content publishing → "Container publishing status": the status of a
    // container that has been published reads PUBLISHED, so only ERROR and
    // EXPIRED name a failure.
    $adapter = instagramAdapter([
        ['body' => SocialFixtures::INSTAGRAM_QUOTA],
        ['body' => SocialFixtures::INSTAGRAM_CONTAINER_RESPONSE],
        ['body' => '{"id":"17900000000000002","status_code":"PUBLISHED"}'],
    ], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: SocialFixtures::INSTAGRAM_ACCOUNT_ID,
        kind: SocialAccountKind::Business,
        accessToken: 'ACCESS_TOKEN',
        body: SocialFixtures::INSTAGRAM_CAPTION,
        media: new PublishMedia(
            mimeType: SocialFixtures::MIME_JPEG,
            publicUrl: SocialFixtures::INSTAGRAM_IMAGE_URL,
            filename: 'bronz-fonz.jpg',
        ),
    ));

    expect($result->success)->toBeTrue()
        ->and($result->externalId)->toBe(SocialFixtures::INSTAGRAM_MEDIA_ID);
});
