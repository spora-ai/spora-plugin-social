<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

/**
 * One platform's answer, already decoded.
 */
final readonly class PlatformHttpResponse
{
    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $body
     */
    public function __construct(
        public int $status,
        public array $headers,
        public array $body,
        public string $raw,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The platform's own error text, when it sent one.
     *
     * Meta answers with `{"error": {"message": ..., "type": ...}}`,
     * LinkedIn with `{"message": ..., "status": ...}`. Both are read here
     * so the adapters do not each have to know the other's shape.
     */
    public function errorMessage(): ?string
    {
        $error = $this->body['error'] ?? null;

        if (is_array($error)) {
            $message = $error['message'] ?? null;
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        if (is_string($error) && $error !== '') {
            return $error;
        }

        $message = $this->body['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }
}
