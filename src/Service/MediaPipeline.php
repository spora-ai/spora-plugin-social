<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Models\MediaAsset;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Services\MediaArchive\ImageDerivativeFormat;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Throwable;

/**
 * From a Media Archive asset to something a platform can publish.
 *
 * Two shapes come out of here, because the platforms want different things.
 * LinkedIn and Facebook take the bytes, read server-side. Instagram fetches
 * from the internet, so it takes a URL the platform can reach — which
 * means a JPEG derivative (Instagram rejects PNG) served from a public
 * token URL.
 *
 * **A file the network will not take is converted, not refused.** The
 * archive holds a producer that re-encodes an image, so a WebP on its way
 * to a network that takes no WebP becomes a JPEG on the way there — the
 * original stays in the library, untouched. The fit check judges the
 * converted file, so the operator is not told to convert a file the plugin
 * has already converted for them.
 */
final class MediaPipeline
{
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

    public function prepare(MediaAsset $asset, SocialChannel $channel): PreparedMedia
    {
        $source = $this->sourceFor($asset, $channel);

        if ($this->isInstagram($channel)) {
            return new PreparedMedia(
                mediaAssetId: (string) $source['asset']->id,
                mimeType: (string) $source['asset']->mime_type,
                sizeBytes: (int) ($source['asset']->byte_size ?? 0),
                filename: (string) ($source['asset']->filename ?? 'media'),
                publicUrl: $this->mintPublicUrl($source['asset']),
                derivativeFormat: $source['format'],
            );
        }

        return $this->prepareWithBytes($source['asset'], $source['format']);
    }

    /**
     * The media as the platform receives it: its mime type and its size.
     *
     * The publish path checks the prepared media, not the original —
     * Instagram takes no PNG, and LinkedIn takes no WebP, and the pipeline
     * converts both before they go out — so a fit check that judged the
     * original would refuse a post that goes out.
     *
     * The bytes are not read: a fit check asks about a mime type and a
     * size, and LinkedIn and Facebook receive the asset as it is.
     *
     * A conversion that cannot be made — the archive has no producer for
     * an SVG, or the network wants a video — leaves the asset as it is:
     * that is then what the platform would receive, and the fit check says
     * so. {@see prepare()} asks for the conversion itself and refuses
     * instead.
     */
    public function prepareForFit(MediaAsset $asset, SocialChannel $channel): PreparedMedia
    {
        try {
            $source = $this->sourceFor($asset, $channel);
        } catch (Throwable) {
            $source = ['asset' => $asset, 'format' => null];
        }

        return new PreparedMedia(
            mediaAssetId: (string) $source['asset']->id,
            mimeType: (string) $source['asset']->mime_type,
            sizeBytes: (int) ($source['asset']->byte_size ?? 0),
            filename: (string) ($source['asset']->filename ?? 'media'),
            derivativeFormat: $source['format'],
        );
    }

    /**
     * The asset as the channel can take it.
     *
     * A channel that takes the asset's own type gets the asset. A channel
     * that does not gets a converted one, when the archive can make it: the
     * first type the channel accepts that the archive can produce from this
     * asset. The original stays in the library — the derivative is a new
     * row beside it.
     *
     * **The aggregator's own channels are left alone.** One post goes to
     * the aggregator as one file for a group of networks, so a file
     * converted for one of them would not fit the others — the operator's
     * file has to satisfy all of them, and the fit check says which one
     * does not.
     *
     * @return array{asset: MediaAsset, format: ?string}
     */
    private function sourceFor(MediaAsset $asset, SocialChannel $channel): array
    {
        $mime = strtolower((string) $asset->mime_type);

        if (!$channel->isDirect() || in_array($mime, PlatformFit::rule($channel)['mime_types'], true)) {
            return ['asset' => $asset, 'format' => null];
        }

        $format = $this->conversionFor($asset, PlatformFit::rule($channel)['mime_types']);

        if ($format === null) {
            return ['asset' => $asset, 'format' => null];
        }

        return [
            'asset'  => $this->derivatives->createFromRequest($asset, $format),
            'format' => $format,
        ];
    }

    /**
     * The format to convert into: the first the channel accepts that the
     * archive can make from this asset.
     *
     * The channel's own list is the order: LinkedIn takes JPEG before PNG
     * before GIF, so a WebP on its way to LinkedIn becomes a JPEG — the
     * first of them, and the one every network takes.
     *
     * **The archive decides what it can make.** A BMP or an SVG has no
     * producer, and neither has a video, so those are left as they are and
     * the fit check says the truth about them.
     */
    private function conversionFor(MediaAsset $asset, array $accepted): ?string
    {
        $available = [];

        foreach ($this->derivatives->availableOptionsFor($asset) as $option) {
            if ($option['available'] === true) {
                $available[$option['format']] = true;
            }
        }

        foreach ($accepted as $mime) {
            foreach (ImageDerivativeFormat::FORMAT_PRESETS as $preset) {
                if ($preset['kind'] !== ImageDerivativeFormat::KIND_CONVERT) {
                    continue;
                }

                if (strtolower($preset['targetMime']) !== strtolower($mime)) {
                    continue;
                }

                if (!isset($available[$preset['format']])) {
                    continue;
                }

                return $preset['format'];
            }
        }

        return null;
    }

    /**
     * The asset as it is.
     *
     * The aggregator takes the original bytes: it accepts JPEG, PNG, GIF
     * and WebP, and it compresses above a network's limit itself. The
     * preparation the native path does exists for the networks that take
     * fewer types than that.
     */
    public function prepareOriginal(MediaAsset $asset): PreparedMedia
    {
        return $this->prepareWithBytes($asset, null);
    }

    /**
     * Whether the channel is the one that fetches the media itself.
     *
     * Instagram is the native case: it rejects a PNG and fetches the
     * file from a URL, so the pipeline converts one and mints that URL.
     */
    private function isInstagram(SocialChannel $channel): bool
    {
        return $channel->isDirect() && $channel->network === SocialPlatform::Instagram->value;
    }

    /**
     * The bytes the platform receives.
     *
     * The format is carried through so the caller can say what it is
     * sending: a converted file and the original it came from are not the
     * same file, and the operator is told which went out.
     */
    private function prepareWithBytes(MediaAsset $asset, ?string $derivativeFormat): PreparedMedia
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
            derivativeFormat: $derivativeFormat,
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
