<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Spora\Plugins\Social\Model\SocialPlatform;

/**
 * LinkedIn's Posts API.
 *
 * An image is a three-step dance: ask for an upload slot, PUT the bytes
 * into it, then name the resulting image URN in the post. The post itself
 * is one `POST /rest/posts` either way.
 *
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/images-api
 */
final class LinkedInAdapter implements PublisherInterface
{
    public const POSTS_URL = 'https://api.linkedin.com/rest/posts';
    public const IMAGES_URL = 'https://api.linkedin.com/rest/images?action=initializeUpload';
    public const FEED_URL = 'https://www.linkedin.com/feed/update/';

    public function __construct(
        private readonly PlatformApi $api,
    ) {}

    public function platform(): SocialPlatform
    {
        return SocialPlatform::LinkedIn;
    }

    public function publish(PublishRequest $request): PublishResult
    {
        $body = [
            'author'                     => $request->externalId,
            'commentary'                 => $request->body,
            'visibility'                 => 'PUBLIC',
            'distribution'               => [
                'feedDistribution'             => 'MAIN_FEED',
                'targetEntities'               => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState'            => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        if ($request->media !== null) {
            if (!$request->media->isImage()) {
                return PublishResult::failed('LinkedIn v1 publishes images only.');
            }

            $upload = $this->uploadImage($request);
            if ($upload['id'] === null) {
                return PublishResult::failed(
                    $upload['error'] ?? 'LinkedIn refused the image upload.',
                );
            }

            $body['content'] = ['media' => ['id' => $upload['id']]];
        }

        $response = $this->api->http->postJson(
            self::POSTS_URL,
            $body,
            [],
            $this->api->versions->linkedInRestHeaders($request->accessToken),
        );

        if (!$response->isSuccessful()) {
            return PublishResult::failed(
                $response->errorMessage() ?? 'LinkedIn refused the post (HTTP ' . $response->status . ').',
            );
        }

        $externalId = $this->externalId($response);
        if ($externalId === null) {
            return PublishResult::failed('LinkedIn accepted the post but returned no id.');
        }

        return PublishResult::published($externalId, self::FEED_URL . $externalId);
    }

    /**
     * Take the post back down.
     *
     * "Post deletions are idempotent. Deletion requests for a previously
     * deleted UGC Post will return a `204` code - No Content."
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
     */
    public function delete(DeleteRequest $request): PublishResult
    {
        $response = $this->api->http->request(
            'DELETE',
            self::POSTS_URL . '/' . rawurlencode($request->externalId),
            [
                'headers' => $this->api->versions->linkedInRestHeaders($request->accessToken) + [
                    'X-RestLi-Method' => 'DELETE',
                ],
            ],
        );

        if (!$response->isSuccessful()) {
            return PublishResult::failed(
                $response->errorMessage() ?? 'LinkedIn refused to delete the post (HTTP ' . $response->status . ').',
            );
        }

        return PublishResult::published($request->externalId);
    }

    /**
     * Reserve an upload slot, send the bytes, and return the image URN the
     * post has to name.
     *
     * @return array{id: ?string, error: ?string}
     */
    private function uploadImage(PublishRequest $request): array
    {
        $media = $request->media;
        if ($media === null || $media->bytes === null) {
            return ['id' => null, 'error' => 'The image has no bytes to upload.'];
        }

        $initialize = $this->api->http->postJson(
            self::IMAGES_URL,
            ['initializeUploadRequest' => ['owner' => $request->externalId]],
            [],
            $this->api->versions->linkedInRestHeaders($request->accessToken),
        );

        if (!$initialize->isSuccessful()) {
            return [
                'id'    => null,
                'error' => $initialize->errorMessage() ?? 'LinkedIn refused to open an upload slot.',
            ];
        }

        $value = $initialize->body['value'] ?? null;
        if (!is_array($value)) {
            return ['id' => null, 'error' => 'LinkedIn did not return an upload slot.'];
        }

        $uploadUrl = $value['uploadUrl'] ?? null;
        $urn = $value['image'] ?? null;

        if (!is_string($uploadUrl) || $uploadUrl === '' || !is_string($urn) || $urn === '') {
            return ['id' => null, 'error' => 'LinkedIn did not return an upload slot.'];
        }

        $upload = $this->api->http->request('PUT', $uploadUrl, [
            'body'    => $media->bytes,
            'headers' => ['Content-Type' => $media->mimeType],
        ]);

        if (!$upload->isSuccessful()) {
            return [
                'id'    => null,
                'error' => $upload->errorMessage() ?? 'LinkedIn refused the image upload.',
            ];
        }

        return ['id' => $this->imageUrn($urn), 'error' => null];
    }

    /**
     * The image URN the post names.
     *
     * The Images API answers with `urn:li:image:{id}` and the post uses it
     * unchanged. The legacy Vector API answered with
     * `urn:li:digitalMediaAsset:{id}`, which has to be rewritten — "if an
     * Urn like `urn:li:digitalMediaAsset:ABC123` is returned then use
     * `urn:li:image:ABC123`".
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
     */
    private function imageUrn(string $urn): string
    {
        if (str_starts_with($urn, 'urn:li:digitalMediaAsset:')) {
            return 'urn:li:image:' . substr($urn, strlen('urn:li:digitalMediaAsset:'));
        }

        return $urn;
    }

    /**
     * The post id.
     *
     * "You should get a 201 response and the response header x-restli-id
     * contains the Post ID such as `urn:li:share:6844785523593134080`".
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
     */
    private function externalId(PlatformHttpResponse $response): ?string
    {
        $id = $response->body['id'] ?? null;
        if (is_string($id) && $id !== '') {
            return $id;
        }

        $header = $response->headers['x-restli-id'] ?? null;

        return is_string($header) && $header !== '' ? $header : null;
    }
}
