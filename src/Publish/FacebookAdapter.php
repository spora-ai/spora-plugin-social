<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Service\ApiVersions;

/**
 * Facebook's Graph API, publishing as a Page.
 *
 * The token on the call is the connection's: under the Meta Unified Login
 * it is a Business Integration System User Access Token, which "belongs to
 * your client's business portfolio" and "covers every asset your client
 * shared with your app". There is no page-token exchange any more.
 *
 * A post without an attachment goes to `/{page-id}/feed`; an image goes to
 * `/{page-id}/photos`, which is the only endpoint that takes the file
 * itself; a video goes to `/{page-id}/videos`, which fetches the file from
 * a URL.
 *
 * @see https://developers.facebook.com/docs/pages-api/posts
 * @see https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-unified-login/authentication
 */
final class FacebookAdapter implements PublisherInterface
{
    public const POST_URL = 'https://www.facebook.com/';

    public function __construct(
        private readonly PlatformApi $api,
    ) {}

    public function platform(): SocialPlatform
    {
        return SocialPlatform::Facebook;
    }

    public function publish(PublishRequest $request): PublishResult
    {
        if ($request->media === null) {
            return $this->publishText($request);
        }

        if ($request->media->isVideo()) {
            return $this->publishVideo($request);
        }

        return $this->publishPhoto($request);
    }

    /**
     * Take the Page post back down.
     *
     * "To delete a Page post, send a `DELETE` request to the
     * `/page_post_id` endpoint" — the answer is `{"success": true}`.
     *
     * The connection's token goes on the call, as it does on the publish.
     *
     * @see https://developers.facebook.com/docs/pages-api/posts
     */
    public function delete(DeleteRequest $request): PublishResult
    {
        $response = $this->api->http->request(
            'DELETE',
            $this->api->versions->graphUrl(ApiVersions::GRAPH_HOST, '/' . $request->externalId),
            [
                'query' => ['access_token' => $request->accessToken],
            ],
        );

        if (!$response->isSuccessful()) {
            return PublishResult::failed(
                $response->errorMessage() ?? 'Facebook refused to delete the post (HTTP ' . $response->status . ').',
            );
        }

        $success = $response->body['success'] ?? null;

        if ($success !== true) {
            return PublishResult::failed('Facebook did not confirm the deletion.');
        }

        return PublishResult::published($request->externalId);
    }

    private function publishText(PublishRequest $request): PublishResult
    {
        $response = $this->api->http->request('POST', $this->pageUrl($request, '/feed'), [
            'body'    => [
                'message'       => $request->body,
                'published'     => 'true',
                'access_token'  => $request->accessToken,
            ],
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        return $this->result($response);
    }

    private function publishPhoto(PublishRequest $request): PublishResult
    {
        $media = $request->media;
        if ($media === null || $media->bytes === null) {
            return PublishResult::failed('The Facebook photo upload needs the image bytes.');
        }

        $boundary = MultipartBody::boundary();
        $body = MultipartBody::build(
            $boundary,
            [
                'message'      => $request->body,
                'published'    => 'true',
                'access_token' => $request->accessToken,
            ],
            [
                'source' => [
                    'filename'     => $media->filename,
                    'content'      => $media->bytes,
                    'content_type' => $media->mimeType,
                ],
            ],
        );

        $response = $this->api->http->request('POST', $this->pageUrl($request, '/photos'), [
            'body'    => $body,
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
        ]);

        return $this->result($response, 'post_id');
    }

    private function publishVideo(PublishRequest $request): PublishResult
    {
        $media = $request->media;
        if ($media === null || $media->publicUrl === null) {
            return PublishResult::failed('Facebook fetches the video itself, so it needs a publicly reachable URL.');
        }

        $response = $this->api->http->request('POST', $this->pageUrl($request, '/videos'), [
            'body'    => [
                'file_url'     => $media->publicUrl,
                'description'  => $request->body,
                'published'    => 'true',
                'access_token' => $request->accessToken,
            ],
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        return $this->result($response);
    }

    private function pageUrl(PublishRequest $request, string $suffix): string
    {
        return $this->api->versions->graphUrl(ApiVersions::GRAPH_HOST, '/' . $request->externalId . $suffix);
    }

    private function result(PlatformHttpResponse $response, string $idKey = 'id'): PublishResult
    {
        if (!$response->isSuccessful()) {
            return PublishResult::failed(
                $response->errorMessage() ?? 'Facebook refused the post (HTTP ' . $response->status . ').',
            );
        }

        $id = $response->body[$idKey] ?? null;
        if (!is_string($id) || $id === '') {
            return PublishResult::failed('Facebook accepted the post but returned no id.');
        }

        return PublishResult::published($id, self::POST_URL . $id);
    }
}
