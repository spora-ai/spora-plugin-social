<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator\Zernio;

/**
 * One answer from Zernio, already decoded.
 *
 * A refusal is a value, not an exception: Zernio refusing a post is a
 * normal outcome the publisher has to read, and the `207` and the `409`
 * are answers the publisher branches on rather than failures.
 */
final readonly class ZernioResponse
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $body,
        public array $headers = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Zernio's own words about the failure.
     *
     * The envelope is `error` for the message and `code` for the
     * machine-readable reason; the body of a refusal is the operator's
     * only clue, so it travels to the target unchanged.
     */
    public function errorMessage(): ?string
    {
        $error = $this->body['error'] ?? null;

        if (is_string($error) && $error !== '') {
            return $error;
        }

        $message = $this->body['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * The machine-readable reason Zernio gave.
     */
    public function errorCode(): ?string
    {
        $code = $this->body['code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}
