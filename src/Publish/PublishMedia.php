<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

/**
 * The bytes, or the public URL, of the one attachment being published.
 *
 * LinkedIn and Facebook take the bytes — the platform pulls nothing.
 * Instagram fetches from the internet, so it takes a URL that the platform
 * can reach, which means the bytes have to be served publicly.
 */
final readonly class PublishMedia
{
    public function __construct(
        public string $mimeType,
        public ?string $bytes = null,
        public ?string $publicUrl = null,
        public string $filename = 'media',
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mimeType, 'video/');
    }
}
