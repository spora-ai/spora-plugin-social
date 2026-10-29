<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Service\ApiVersions;

/**
 * Instagram's two-step publish.
 *
 * A container is created first and published second. Media is mandatory:
 * Instagram has no text-only post, so a request without an attachment is
 * refused here rather than sent and rejected.
 *
 * Instagram fetches the media from the internet, so the image has to be
 * served from a URL the platform can reach — which is why the media
 * pipeline prepares a public URL before the publish.
 *
 * The host is the Graph host: the Instagram API with Unified Login is
 * "built on Facebook Login for Business" and its calls go to
 * `graph.facebook.com`, with the Instagram account id in the path.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/content-publishing
 * @see https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-unified-login
 */
final class InstagramAdapter implements PublisherInterface
{
    public const POST_URL = 'https://www.instagram.com/p/';

    /**
     * What the operator is told when they ask for the impossible.
     *
     * The delete endpoint exists, but only for the Instagram API with
     * Facebook Login: "This api only supports Instagram API with Facebook
     * login only." This plugin speaks the Unified Login, so the endpoint
     * is not there for it — and a request would be refused with something
     * opaque.
     *
     * @see https://developers.facebook.com/documentation/instagram-platform/reference/instagram-media
     */
    public const CANNOT_DELETE = 'Instagram cannot take a published post back down: the Instagram API has no delete '
        . 'for published media under the Unified Login. Delete it in the Instagram app.';

    public function __construct(
        private readonly PlatformApi $api,
    ) {}

    public function platform(): SocialPlatform
    {
        return SocialPlatform::Instagram;
    }

    public function publish(PublishRequest $request): PublishResult
    {
        $media = $request->media;
        if ($media === null) {
            return PublishResult::failed('Instagram cannot publish a post without media.');
        }

        if ($media->publicUrl === null) {
            return PublishResult::failed('Instagram fetches the media itself, so it needs a publicly reachable URL.');
        }

        $limit = $this->publishingLimit($request);
        if ($limit !== null) {
            return $limit;
        }

        $container = $this->createContainer($request, $media);
        if ($container['id'] === null) {
            return PublishResult::failed(
                $container['error'] ?? 'Instagram refused to create the media container.',
            );
        }

        // The container exists now. The caller is told, so a publish that
        // dies before the container is published leaves an id behind that
        // the reconciliation can ask about.
        if ($request->onContainer !== null) {
            ($request->onContainer)($container['id']);
        }

        $published = $this->api->http->postJson(
            $this->api->versions->graphUrl(ApiVersions::GRAPH_HOST, '/' . $request->externalId . '/media_publish'),
            ['creation_id' => $container['id']],
            [],
            ['Authorization' => 'Bearer ' . $request->accessToken],
        );

        if (!$published->isSuccessful()) {
            return PublishResult::failed(
                $published->errorMessage() ?? 'Instagram refused to publish the container (HTTP ' . $published->status . ').',
            );
        }

        // The container's status reads PUBLISHED once it has gone out, so
        // only a status that names a failure is a failure.
        //
        // @see https://developers.facebook.com/docs/instagram-platform/content-publishing
        $status = $published->body['status_code'] ?? null;
        if (is_string($status) && in_array($status, ['ERROR', 'EXPIRED'], true)) {
            return PublishResult::failed('Instagram did not finish the media: ' . $status . '.');
        }

        $id = $published->body['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return PublishResult::failed('Instagram accepted the post but returned no id.');
        }

        return PublishResult::published(
            $id,
            self::POST_URL . $id,
            containerId: $container['id'],
        );
    }

    /**
     * Instagram cannot take a published post back down.
     *
     * The endpoint exists, but only for the other login type: the delete
     * section of the IG Media reference lists a single requirement
     * column — Instagram API with Facebook Login, a Facebook User access
     * token, `graph.facebook.com` and the `instagram_manage_contents`
     * permission — and says "This api only supports Instagram API with
     * Facebook login only."
     *
     * @see https://developers.facebook.com/documentation/instagram-platform/reference/instagram-media
     */
    public function delete(DeleteRequest $request): PublishResult
    {
        return PublishResult::failed(self::CANNOT_DELETE);
    }

    /**
     * The publishing quota the account has left.
     *
     * "Instagram accounts are limited to 100 API-published posts within a
     * 24-hour moving period... We recommend that your app also enforce the
     * publishing rate limit, especially if your app allows app users to
     * schedule posts to be published in the future."
     *
     * The limit the endpoint reports is the one that counts — the guide
     * says 100 posts, the reference says `quota_total` is currently 50 —
     * so the quota is read rather than assumed.
     *
     * @see https://developers.facebook.com/documentation/instagram-platform/instagram-graph-api/reference/ig-user/content_publishing_limit
     */
    private function publishingLimit(PublishRequest $request): ?PublishResult
    {
        $response = $this->api->http->request('GET', $this->api->versions->graphUrl(
            ApiVersions::GRAPH_HOST,
            '/' . $request->externalId . '/content_publishing_limit',
        ), [
            'query'   => ['fields' => 'quota_usage,config'],
            'headers' => ['Authorization' => 'Bearer ' . $request->accessToken],
        ]);

        if (!$response->isSuccessful()) {
            return null;
        }

        $data = $response->body['data'][0] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $usage = $data['quota_usage'] ?? null;
        $total = $data['config']['quota_total'] ?? null;

        if (!is_numeric($usage) || !is_numeric($total)) {
            return null;
        }

        if ((int) $usage < (int) $total) {
            return null;
        }

        return PublishResult::failed(sprintf(
            'Instagram has published %d of its %d posts in the last 24 hours; the platform refuses more until the window moves.',
            (int) $usage,
            (int) $total,
        ));
    }

    /**
     * @return array{id: ?string, error: ?string}
     */
    private function createContainer(PublishRequest $request, PublishMedia $media): array
    {
        $body = $media->isVideo()
            ? ['video_url' => $media->publicUrl, 'caption' => $request->body]
            : ['image_url' => $media->publicUrl, 'caption' => $request->body];

        $response = $this->api->http->postJson(
            $this->api->versions->graphUrl(ApiVersions::GRAPH_HOST, '/' . $request->externalId . '/media'),
            $body,
            [],
            ['Authorization' => 'Bearer ' . $request->accessToken],
        );

        if (!$response->isSuccessful()) {
            return [
                'id'    => null,
                'error' => $response->errorMessage() ?? 'Instagram refused to create the media container.',
            ];
        }

        $id = $response->body['id'] ?? null;

        return [
            'id'    => is_string($id) && $id !== '' ? $id : null,
            'error' => null,
        ];
    }
}
