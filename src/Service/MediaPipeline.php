<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Models\MediaAsset;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaDerivativeService;

/**
 * From a Media Archive asset to something a platform can publish.
 *
 * Two shapes come out of here, because the platforms want different things.
 * LinkedIn and Facebook take the bytes, read server-side. Instagram fetches
 * from the internet, so it takes a URL the platform can reach — which
 * means a JPEG derivative (Instagram rejects PNG) served from a public
 * token URL.
 */
final class MediaPipeline
{
    /**
     * Core's own image producer ships the preset; the plugin ships no
     * producer of its own.
     */
    public const JPEG_FORMAT = 'format-jpeg';

    public function __construct(
        private readonly MediaDerivativeService $derivatives,
        private readonly MediaAssetReader $reader,
        private readonly SocialSettings $settings,
    ) {}

    /**
     * The asset, if it belongs to the principal.
     *
     * Strictly scoped: an asset of another principal is not this
     * principal's to publish, and the media picker the plugin uses is
     * principal-scoped too.
     */
    public function findAsset(string $assetId, int $principalId): MediaAsset
    {
        $asset = MediaAsset::query()
            ->where('id', $assetId)
            ->where('principal_id', $principalId)
            ->first();

        if ($asset === null) {
            throw SocialException::notFound('Media asset ' . $assetId . ' was not found for this principal.');
        }

        return $asset;
    }

    public function prepare(MediaAsset $asset, SocialPlatform $platform): PreparedMedia
    {
        if ($platform === SocialPlatform::Instagram) {
            return $this->prepareForInstagram($asset);
        }

        return $this->prepareWithBytes($asset);
    }

    /**
     * Instagram: a JPEG, served from a URL the platform can fetch.
     *
     * A PNG is converted by core's producer; the derivative is what gets
     * published, so the original stays untouched.
     */
    private function prepareForInstagram(MediaAsset $asset): PreparedMedia
    {
        $source = $asset;
        $derivativeFormat = null;
        $mime = strtolower((string) $asset->mime_type);

        if (str_starts_with($mime, 'image/') && $mime !== 'image/jpeg') {
            $source = $this->derivatives->createFromRequest($asset, self::JPEG_FORMAT);
            $derivativeFormat = self::JPEG_FORMAT;
        }

        return new PreparedMedia(
            mediaAssetId: (string) $source->id,
            mimeType: (string) $source->mime_type,
            sizeBytes: (int) ($source->byte_size ?? 0),
            filename: (string) ($source->filename ?? 'media'),
            publicUrl: $this->mintPublicUrl($source),
            derivativeFormat: $derivativeFormat,
        );
    }

    private function prepareWithBytes(MediaAsset $asset): PreparedMedia
    {
        $read = $this->reader->readAsset((string) $asset->id, null);

        if ($read === null || !isset($read['bytes'])) {
            throw SocialException::notFound('The media asset ' . $asset->id . ' has no readable bytes.');
        }

        return new PreparedMedia(
            mediaAssetId: (string) $asset->id,
            mimeType: (string) $read['mime'],
            sizeBytes: (int) ($asset->byte_size ?? strlen((string) $read['bytes'])),
            filename: (string) ($asset->filename ?? 'media'),
            bytes: (string) $read['bytes'],
        );
    }

    /**
     * Mint a public token for the asset and return the URL Instagram will
     * fetch.
     *
     * The URL shape is core's public media route — the plugin builds it
     * rather than asking core, because core's serializer keeps that helper
     * private.
     */
    public function mintPublicUrl(MediaAsset $asset): string
    {
        $asset->public_access_token = MediaArchiveService::mintPublicAccessToken();
        $asset->save();

        return $this->publicUrl($asset);
    }

    public function publicUrl(MediaAsset $asset): string
    {
        $base = rtrim($this->settings->appUrl() ?? '', '/');

        return $base . '/api/v1/public/media/' . $asset->id . '?token=' . (string) $asset->public_access_token;
    }
}
