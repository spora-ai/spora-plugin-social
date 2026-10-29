<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tools;

use Spora\Models\MediaAsset;
use Spora\Plugins\Social\Http\SocialPostResource;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Service\MediaPipeline;
use Spora\Plugins\Social\Service\PlatformFit;
use Spora\Plugins\Social\Service\SocialPostService;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * The attachments: what the principal has in its library, whether an asset
 * fits a platform, and how to prepare it for Instagram.
 *
 * `check_fit` is the operation that keeps a PNG off an Instagram post:
 * Instagram rejects PNG outright, and the plugin would otherwise fail the
 * publish with the platform's error text instead of a remedy.
 */
#[Tool(
    name: 'social_media',
    description: 'Work with the media a post carries. List this principal\'s Media Archive assets, check whether an asset fits a platform, prepare it for Instagram as a JPEG with a public URL, or attach and detach it on a post. The rules: LinkedIn takes JPEG, PNG or GIF up to 8 MB and no video; Facebook takes JPEG, PNG, GIF, BMP or MP4 up to 1 GB; Instagram REQUIRES media, takes only JPEG or MP4 up to 100 MB and rejects PNG — attach the original asset id and the backend converts it for you.',
    displayName: 'Social Media',
    category: 'social',
    icon: 'globe',
    recommendsSkills: ['social-poster'],
)]
#[ToolOperation(
    name: 'list_assets',
    description: 'List the Media Archive assets of this principal with their mime type, size and dimensions.',
    operatorDescription: 'List the media library',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'check_fit',
    description: 'Check whether an asset fits a platform, and say what to do about it when it does not. Instagram needs a JPEG and cannot publish without media at all.',
    operatorDescription: 'Check a media asset against a platform',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'prepare',
    description: 'Prepare an asset for Instagram: the JPEG derivative and the public URL Instagram will fetch. Nothing else is needed afterwards — attach the original asset id to the post and the backend publishes the converted image.',
    operatorDescription: 'Prepare a media asset for Instagram',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'attach',
    description: 'Attach an asset to a post. A post carries one attachment, so a post that already has one refuses this. A post that is already scheduled changes what goes out, so this needs the operator to agree — attach the media when you create the post instead.',
    operatorDescription: 'Attach media to a post',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'detach',
    description: 'Take an asset off a post. A post that is already scheduled changes what goes out, so this needs the operator to agree.',
    operatorDescription: 'Detach media from a post',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolParameter(name: 'media_asset_id', type: 'string', description: 'The Media Archive asset id (a UUID). Required for check_fit, prepare, attach and detach.', required: ['check_fit', 'prepare', 'attach', 'detach'])]
#[ToolParameter(name: 'post_id', type: 'integer', description: 'The post the attachment belongs to.', required: ['attach', 'detach'])]
#[ToolParameter(name: 'platforms', type: 'array', description: 'The platforms to check against: linkedin, facebook, instagram.', required: false, items: ['type' => 'string'])]
#[ToolParameter(name: 'media_kind', type: 'string', description: 'Filter the asset list by kind: image, video, audio or document.', required: false)]
#[ToolParameter(name: 'limit', type: 'integer', description: 'How many assets to return (default 20, capped at 100).', required: false, default: 20)]
final class SocialMediaTool extends AbstractSocialTool
{
    public function __construct(
        private readonly MediaPipeline $media,
        private readonly SocialPostService $posts,
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        try {
            $principalId = $this->principalId($agentId, $context);
            $operation = (string) ($arguments['action'] ?? 'list_assets');

            return match ($operation) {
                'list_assets' => $this->listAssets($principalId, $arguments),
                'check_fit' => $this->checkFit($principalId, $arguments),
                'prepare' => $this->prepare($principalId, $arguments),
                'attach' => $this->attach($principalId, $arguments),
                'detach' => $this->detach($principalId, $arguments),
                default => new ToolResult(false, "Invalid action '{$operation}'. Use list_assets, check_fit, prepare, attach or detach."),
            };
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? 'list_assets');
        $assetId = (string) ($arguments['media_asset_id'] ?? '');

        if ($assetId !== '') {
            return 'Social media ' . $operation . ': ' . $assetId;
        }

        return 'Social media ' . $operation;
    }

    private function listAssets(int $principalId, array $arguments): ToolResult
    {
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 20)));

        $assets = MediaAsset::query()
            ->where('principal_id', $principalId)
            ->whereNotIn('id', \Spora\Models\MediaDerivative::query()->select('derivative_id'))
            ->orderByDesc('created_at')
            ->limit($limit)
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
            ];
        }

        return ToolResult::ok(
            count($rows) . ' media asset(s) in this principal\'s library.',
            ['assets' => $rows],
        );
    }

    private function checkFit(int $principalId, array $arguments): ToolResult
    {
        $asset = $this->media->findAsset($this->assetId($arguments), $principalId);

        $platforms = $this->platformList($arguments['platforms'] ?? null, SocialPlatform::values());
        $perPlatform = [];

        foreach ($platforms as $name) {
            $platform = SocialPlatform::tryFromName($name);
            if ($platform === null) {
                continue;
            }

            $fit = PlatformFit::check($platform, $asset->mime_type, $asset->byte_size);
            $perPlatform[] = [
                'platform' => $platform->value,
                'fits'     => $fit['fits'],
                'reasons'  => $fit['reasons'],
                'remedy'   => $fit['remedy'],
            ];
        }

        $fits = true;
        foreach ($perPlatform as $entry) {
            if ($entry['fits'] === false) {
                $fits = false;
            }
        }

        return ToolResult::ok(
            $fits ? 'The asset fits every platform asked about.' : 'The asset does not fit every platform asked about.',
            ['fits' => $fits, 'per_platform' => $perPlatform],
        );
    }

    private function prepare(int $principalId, array $arguments): ToolResult
    {
        $asset = $this->media->findAsset($this->assetId($arguments), $principalId);

        $platform = SocialPlatform::tryFromName((string) ($arguments['platform'] ?? 'instagram'))
            ?? SocialPlatform::Instagram;

        $prepared = $this->media->prepare($asset, $platform);

        return ToolResult::ok(
            'Prepared the asset for ' . $platform->value . '.',
            [
                'media_asset_id' => $prepared->mediaAssetId,
                'format'         => $prepared->derivativeFormat ?? $prepared->mimeType,
                'url'            => $prepared->publicUrl,
                'mime_type'      => $prepared->mimeType,
            ],
        );
    }

    private function attach(int $principalId, array $arguments): ToolResult
    {
        $postId = (int) ($arguments['post_id'] ?? 0);
        if ($postId <= 0) {
            return new ToolResult(false, 'Error (VALIDATION_ERROR): post_id is required.');
        }

        $assetId = $this->assetId($arguments);
        $this->media->findAsset($assetId, $principalId);

        $post = $this->posts->attachMedia($postId, $principalId, $assetId);

        return ToolResult::ok(
            'Attached ' . $assetId . ' to post #' . $post->id . '.',
            ['post' => SocialPostResource::toArray($post)],
        );
    }

    private function detach(int $principalId, array $arguments): ToolResult
    {
        $postId = (int) ($arguments['post_id'] ?? 0);
        if ($postId <= 0) {
            return new ToolResult(false, 'Error (VALIDATION_ERROR): post_id is required.');
        }

        $assetId = $this->assetId($arguments);

        $post = $this->posts->detachMedia($postId, $principalId, $assetId);

        return ToolResult::ok(
            'Detached ' . $assetId . ' from post #' . $post->id . '.',
            ['post' => SocialPostResource::toArray($post)],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assetId(array $arguments): string
    {
        $assetId = trim((string) ($arguments['media_asset_id'] ?? ''));

        if ($assetId === '') {
            throw \Spora\Plugins\Social\Exception\SocialException::validation('media_asset_id is required.');
        }

        return $assetId;
    }
}
