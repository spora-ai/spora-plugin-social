<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialPost;

/**
 * The attachment a post carries.
 *
 * One attachment per post: the publish sends one file, so a second
 * would be a file the operator believes is going out and is not.
 *
 * The **original** bytes are what the aggregator takes: it accepts JPEG,
 * PNG, GIF and WebP, and it compresses above a network's limit itself.
 * The native path's preparation exists for Instagram, which takes no PNG
 * at all.
 */
final class PostMedia
{
    public function __construct(
        private readonly MediaPipeline $pipeline,
    ) {}

    /**
     * The post's attachment, as it is.
     */
    public function original(SocialPost $post): ?PreparedMedia
    {
        $attachment = $post->media()->orderBy('position')->first();

        if ($attachment === null) {
            return null;
        }

        if ($post->media()->count() > PlatformFit::MEDIA_PER_POST) {
            throw SocialException::validation(
                'A post carries one attachment. ' . PlatformFit::REMEDY_ONE_ATTACHMENT . '.',
            );
        }

        $asset = $this->pipeline->findAsset((string) $attachment->media_asset_id, (int) $post->principal_id);

        return $this->pipeline->prepareOriginal($asset);
    }
}
