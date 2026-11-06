<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Http;

use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Service\MediaPipeline;
use Spora\Plugins\Social\Service\PlatformFit;
use Spora\Plugins\Social\Service\SocialPostService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The attachments.
 */
final class SocialMediaController extends AbstractSocialController
{
    public function __construct(
        AuthService $auth,
        PrincipalResolver $principals,
        PrincipalService $principalService,
        private readonly MediaPipeline $media,
        private readonly SocialPostService $posts,
    ) {
        parent::__construct($auth, $principals, $principalService);
    }

    public function media(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);
            $mediaKind = $request->query->get('media_kind');

            $assets = MediaAsset::query()
                ->where('principal_id', $principalId)
                ->whereNotIn('id', MediaDerivative::query()->select('derivative_id'))
                ->when(
                    is_string($mediaKind) && $mediaKind !== '',
                    static function ($query) use ($mediaKind): void {
                        $query->where('media_type', $mediaKind);
                    },
                )
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();

            $rows = [];
            foreach ($assets as $asset) {
                $rows[] = [
                    'id'         => (string) $asset->id,
                    'filename'   => $asset->filename,
                    'mime_type'  => $asset->mime_type,
                    'size_bytes' => $asset->byte_size,
                    'width'      => $asset->width,
                    'height'     => $asset->height,
                    // The opaque path the host SPA renders as an `<img src>`
                    // — the column itself, so the composer and the posts
                    // table name the same picture.
                    'asset_url'  => $asset->asset_url,
                ];
            }

            return $this->ok($rows);
        });
    }

    /**
     * Whether an asset fits a channel, and what to do about it when it
     * does not.
     */
    public function checkFit(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);
            $body = $this->body($request);

            $asset = $this->media->findAsset($this->assetId($body), $principalId);

            $channels = [];
            foreach (is_array($body['channels'] ?? null) ? $body['channels'] : [] as $key) {
                $channel = is_string($key) ? SocialChannel::fromKey($key) : null;
                if ($channel !== null) {
                    $channels[] = $channel;
                }
            }

            if ($channels === []) {
                $channels = PlatformFit::channels();
            }

            $perChannel = [];
            $fits = true;

            foreach ($channels as $channel) {
                // The media as the platform receives it: the backend
                // converts a PNG for Instagram before it publishes, so
                // judging the original would warn about a post that goes
                // out.
                $prepared = $this->media->prepareForFit($asset, $channel);

                $fit = PlatformFit::check($channel, $prepared->mimeType, $prepared->sizeBytes);
                $fits = $fits && $fit['fits'];

                $perChannel[] = [
                    'channel'   => $channel->key(),
                    'network'   => $channel->network,
                    'via'       => $channel->via,
                    'fits'      => $fit['fits'],
                    'reasons'   => $fit['reasons'],
                    'remedy'    => $fit['remedy'],
                    'mime_type' => $prepared->mimeType,
                ];
            }

            return $this->ok([
                'fits'        => $fits,
                'per_channel' => $perChannel,
            ]);
        });
    }

    /**
     * Instagram's step: the JPEG derivative and the public URL it fetches.
     */
    public function prepareMedia(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);
            $body = $this->body($request);

            $platform = SocialPlatform::tryFromName((string) ($body['platform'] ?? 'instagram'))
                ?? SocialPlatform::Instagram;

            $asset = $this->media->findAsset($this->assetId($body), $principalId);
            $prepared = $this->media->prepare($asset, SocialChannel::direct($platform->value));

            return $this->ok([
                'media_asset_id' => $prepared->mediaAssetId,
                'format'         => $prepared->derivativeFormat ?? $prepared->mimeType,
                'url'            => $prepared->publicUrl,
                'mime_type'      => $prepared->mimeType,
            ]);
        });
    }

    public function attachMedia(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $body = $this->body($request);

            $postId = (int) ($body['post_id'] ?? 0);
            if ($postId <= 0) {
                throw SocialException::validation('post_id is required.');
            }

            $assetId = $this->assetId($body);
            $this->media->findAsset($assetId, $principalId);

            $post = $this->posts->attachMedia($postId, $principalId, $assetId, (int) ($body['position'] ?? 0));

            return $this->ok(SocialPostResource::toArray($post));
        });
    }

    public function detachMedia(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $body = $this->body($request);

            $postId = (int) ($body['post_id'] ?? 0);
            if ($postId <= 0) {
                throw SocialException::validation('post_id is required.');
            }

            $assetId = $this->assetId($body);

            $post = $this->posts->detachMedia($postId, $principalId, $assetId);

            return $this->ok(SocialPostResource::toArray($post));
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assetId(array $body): string
    {
        $assetId = trim((string) ($body['media_asset_id'] ?? ''));

        if ($assetId === '') {
            throw SocialException::validation('media_asset_id is required.');
        }

        return $assetId;
    }
}
