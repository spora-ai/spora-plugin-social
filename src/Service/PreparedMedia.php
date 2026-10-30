<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

/**
 * The attachment as it will be handed to a platform.
 */
final readonly class PreparedMedia
{
    public function __construct(
        public string $mediaAssetId,
        public string $mimeType,
        public int $sizeBytes,
        public string $filename,
        public ?string $bytes = null,
        public ?string $publicUrl = null,
        public ?string $derivativeFormat = null,
    ) {}
}
