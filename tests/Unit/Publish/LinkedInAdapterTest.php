<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Publish\DeleteRequest;
use Spora\Plugins\Social\Publish\LinkedInAdapter;
use Spora\Plugins\Social\Publish\PublishMedia;
use Spora\Plugins\Social\Publish\PublishRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The recorded wire fixtures of LinkedIn's Posts API.
 *
 * Every expectation below is transcribed from the documentation, with the
 * URL it came from. The point of a contract test is that somebody can
 * re-check it against the docs when the API moves.
 *
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/images-api
 *
 * @param list<array{body: string, status?: int, headers?: array<string, string>}> $responses
 * @param list<array{method: string, url: string, options: array<string, mixed>}> $seen
 */
function linkedInAdapter(array $responses, array &$seen): LinkedInAdapter
{
    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen, $responses) {
        $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];

        $response = $responses[count($seen) - 1] ?? ['body' => '{}'];

        $info = ['http_code' => $response['status'] ?? 200];

        if (($response['headers'] ?? []) !== []) {
            $info['response_headers'] = $response['headers'];
        }

        return new MockResponse($response['body'], $info);
    });

    return new LinkedInAdapter(platformApi($http));
}

it('posts the documented text-only body with both version headers', function () {
    $seen = [];

    // Posts API → "Text-Only Post Creation Sample Request":
    //   POST https://api.linkedin.com/rest/posts
    //   -H 'Authorization: Bearer {INSERT_TOKEN}'
    //   -H 'X-Restli-Protocol-Version: 2.0.0'
    //   -H 'Linkedin-Version: {version number in the format YYYYMM}'
    //   -H 'Content-Type: application/json'
    //   --data '{ "author": "urn:li:organization:5515715", "commentary": "Sample text Post", ... }'
    $adapter = linkedInAdapter([[
        'body' => '',
        'status' => 201,
        'headers' => ['x-restli-id' => 'urn:li:share:6844785523593134080'],
    ]], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: 'urn:li:organization:5515715',
        kind: SocialAccountKind::Organization,
        accessToken: 'INSERT_TOKEN',
        body: 'Sample text Post',
    ));

    expect($result->success)->toBeTrue();

    expect($seen)->toHaveCount(1);

    $request = $seen[0];
    expect($request['method'])->toBe('POST')
        ->and($request['url'])->toBe('https://api.linkedin.com/rest/posts');

    $headers = sentHeaders($request['options']);
    expect($headers)->toHaveKey('authorization')
        ->and($headers['authorization'])->toBe('Bearer INSERT_TOKEN')
        ->and($headers)->toHaveKey('x-restli-protocol-version')
        ->and($headers['x-restli-protocol-version'])->toBe('2.0.0')
        ->and($headers)->toHaveKey('linkedin-version')
        ->and($headers['linkedin-version'])->toBe('202610')
        ->and($headers['content-type'])->toBe('application/json');

    expect(sentJson($request['options']))->toBe([
        'author'                     => 'urn:li:organization:5515715',
        'commentary'                 => 'Sample text Post',
        'visibility'                 => 'PUBLIC',
        'distribution'               => [
            'feedDistribution'             => 'MAIN_FEED',
            'targetEntities'               => [],
            'thirdPartyDistributionChannels' => [],
        ],
        'lifecycleState'            => 'PUBLISHED',
        'isReshareDisabledByAuthor' => false,
    ]);
});

it('reads the post id from the x-restli-id header', function () {
    $seen = [];

    // Posts API → "Post Creation Response": "The Post is created with a
    // 201 response and the response header `x-restli-id` contains the Post
    // ID such as `urn:li:share:6844785523593134080`".
    $adapter = linkedInAdapter([[
        'body' => '',
        'status' => 201,
        'headers' => ['x-restli-id' => 'urn:li:share:6844785523593134080'],
    ]], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: 'urn:li:organization:5515715',
        kind: SocialAccountKind::Organization,
        accessToken: 'INSERT_TOKEN',
        body: 'Sample text Post',
    ));

    expect($result->externalId)->toBe('urn:li:share:6844785523593134080')
        ->and($result->externalUrl)->toBe('https://www.linkedin.com/feed/update/urn:li:share:6844785523593134080');
});

it('uploads an image in the documented three steps and names the image urn', function () {
    $seen = [];

    // Image API → "Initialize Image Upload":
    //   POST https://api.linkedin.com/rest/images?action=initializeUpload
    //   --data '{ "initializeUploadRequest": { "owner": "urn:li:organization:5583111" } }'
    //   → { "value": { "uploadUrlExpiresAt": ..., "uploadUrl": "...", "image": "urn:li:image:C4E10AQFoyyAjHPMQuQ" } }
    //
    // Image API → "Create Image content":
    //   POST https://api.linkedin.com/rest/posts
    //   --data '{ "author": "...", "commentary": "test strings!", ..., "content": { "media": { "altText": "...", "id": "urn:li:image:C5610AQFj6TdYowm17w" } }, ... }'
    $adapter = linkedInAdapter([
        [
            'body' => json_encode([
                'value' => [
                    'uploadUrlExpiresAt' => 1650567510704,
                    'uploadUrl' => 'https://www.linkedin.com/dms-uploads/C4E10AQFoyyAjHPMQuQ/uploaded-image/0',
                    'image' => 'urn:li:image:C4E10AQFoyyAjHPMQuQ',
                ],
            ]),
        ],
        ['body' => '', 'status' => 201],
        [
            'body' => '',
            'status' => 201,
            'headers' => ['x-restli-id' => 'urn:li:share:6844785523593134080'],
        ],
    ], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: 'urn:li:organization:5583111',
        kind: SocialAccountKind::Organization,
        accessToken: 'INSERT_TOKEN',
        body: 'test strings!',
        media: new PublishMedia(mimeType: 'image/png', bytes: 'PNGBYTES', filename: 'chart.png'),
    ));

    expect($result->success)->toBeTrue();
    expect($seen)->toHaveCount(3);

    expect($seen[0]['method'])->toBe('POST')
        ->and($seen[0]['url'])->toBe('https://api.linkedin.com/rest/images?action=initializeUpload')
        ->and(sentJson($seen[0]['options']))->toBe([
            'initializeUploadRequest' => ['owner' => 'urn:li:organization:5583111'],
        ]);

    $initializeHeaders = sentHeaders($seen[0]['options']);
    expect($initializeHeaders)->toHaveKey('linkedin-version')
        ->and($initializeHeaders['linkedin-version'])->toBe('202610');

    expect($seen[1]['method'])->toBe('PUT')
        ->and($seen[1]['url'])->toBe('https://www.linkedin.com/dms-uploads/C4E10AQFoyyAjHPMQuQ/uploaded-image/0')
        ->and($seen[1]['options']['body'])->toBe('PNGBYTES');

    expect(sentJson($seen[2]['options']))->toBe([
        'author'                     => 'urn:li:organization:5583111',
        'commentary'                 => 'test strings!',
        'visibility'                 => 'PUBLIC',
        'distribution'               => [
            'feedDistribution'             => 'MAIN_FEED',
            'targetEntities'               => [],
            'thirdPartyDistributionChannels' => [],
        ],
        'lifecycleState'            => 'PUBLISHED',
        'isReshareDisabledByAuthor' => false,
        'content'                   => ['media' => ['id' => 'urn:li:image:C4E10AQFoyyAjHPMQuQ']],
    ]);
});

it('rewrites the legacy vector urn into the image urn the post names', function () {
    $seen = [];

    // The Images API answers with `urn:li:image:{id}`. The Vector API it
    // replaced answered with `urn:li:digitalMediaAsset:{id}` — "if an Urn
    // like `urn:li:digitalMediaAsset:ABC123` is returned then use
    // `urn:li:image:ABC123`".
    //
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
    $adapter = linkedInAdapter([
        [
            'body' => json_encode([
                'value' => [
                    'uploadUrlExpiresAt' => 1650567510704,
                    'uploadUrl' => 'https://www.linkedin.com/dms-uploads/C4E10AQFoyyAjHPMQuQ/uploaded-image/0',
                    'image' => 'urn:li:digitalMediaAsset:ABC123',
                ],
            ]),
        ],
        ['body' => '', 'status' => 201],
        [
            'body' => '',
            'status' => 201,
            'headers' => ['x-restli-id' => 'urn:li:share:6844785523593134080'],
        ],
    ], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: 'urn:li:organization:5583111',
        kind: SocialAccountKind::Organization,
        accessToken: 'INSERT_TOKEN',
        body: 'test strings!',
        media: new PublishMedia(mimeType: 'image/png', bytes: 'PNGBYTES', filename: 'chart.png'),
    ));

    expect($result->success)->toBeTrue()
        ->and(sentJson($seen[2]['options'])['content'])->toBe(['media' => ['id' => 'urn:li:image:ABC123']]);
});

it('deletes the post with the documented request', function () {
    $seen = [];

    // Posts API → "Delete Posts":
    //   DELETE https://api.linkedin.com/rest/posts/{encoded ugcPostUrn|shareUrn}
    //   -H 'X-Restli-Protocol-Version: 2.0.0'
    //   -H 'Linkedin-Version: {version number in the format YYYYMM}'
    //   -H 'X-RestLi-Method: DELETE'
    //   -H 'Authorization: Bearer {INSERT_TOKEN}'
    //   → "A successful response returns a 204 code notification."
    $adapter = linkedInAdapter([['body' => '', 'status' => 204]], $seen);

    $result = $adapter->delete(new DeleteRequest(
        externalId: 'urn:li:share:6844785523593134080',
        kind: SocialAccountKind::Organization,
        accessToken: 'INSERT_TOKEN',
    ));

    expect($result->success)->toBeTrue();
    expect($seen)->toHaveCount(1);

    $request = $seen[0];
    expect($request['method'])->toBe('DELETE')
        ->and($request['url'])->toBe('https://api.linkedin.com/rest/posts/urn%3Ali%3Ashare%3A6844785523593134080');

    $headers = sentHeaders($request['options']);
    expect($headers['linkedin-version'])->toBe('202610')
        ->and($headers['x-restli-protocol-version'])->toBe('2.0.0')
        ->and($headers['x-restli-method'])->toBe('DELETE')
        ->and($headers['authorization'])->toBe('Bearer INSERT_TOKEN');
});

it('reports the platform error when the delete is refused', function () {
    $seen = [];

    $adapter = linkedInAdapter([[
        'body' => '{"message":"RESOURCE_NOT_FOUND","status":404}',
        'status' => 404,
    ]], $seen);

    $result = $adapter->delete(new DeleteRequest(
        externalId: 'urn:li:share:6844785523593134080',
        kind: SocialAccountKind::Organization,
        accessToken: 'INSERT_TOKEN',
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('RESOURCE_NOT_FOUND');
});

it('reports the platform error when the post is refused', function () {
    $seen = [];

    $adapter = linkedInAdapter([[
        'body' => '{"message":"Invalid author","status":422}',
        'status' => 422,
    ]], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: 'urn:li:person:7',
        kind: SocialAccountKind::Member,
        accessToken: 'INSERT_TOKEN',
        body: 'Hello',
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Invalid author');
});

it('refuses a video, which v1 does not publish', function () {
    $seen = [];
    $adapter = linkedInAdapter([['body' => '{}']], $seen);

    $result = $adapter->publish(new PublishRequest(
        externalId: 'urn:li:organization:5515715',
        kind: SocialAccountKind::Organization,
        accessToken: 'INSERT_TOKEN',
        body: 'A video',
        media: new PublishMedia(mimeType: 'video/mp4', bytes: 'MP4', filename: 'clip.mp4'),
    ));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('images only')
        ->and($seen)->toHaveCount(0);
});
