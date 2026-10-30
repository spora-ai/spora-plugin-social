<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Exception;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every failure the plugin reports, with the wire code and the HTTP status
 * it travels as.
 *
 * The HTTP layer and the tools read the same exception, so a failure an
 * agent sees and a failure an operator sees carry the same code.
 */
final class SocialException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = Response::HTTP_BAD_REQUEST,
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message): self
    {
        return new self('VALIDATION_ERROR', $message, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function notFound(string $message): self
    {
        return new self('NOT_FOUND', $message, Response::HTTP_NOT_FOUND);
    }

    public static function forbidden(string $message): self
    {
        return new self('FORBIDDEN', $message, Response::HTTP_FORBIDDEN);
    }

    public static function conflict(string $message): self
    {
        return new self('CONFLICT', $message, Response::HTTP_CONFLICT);
    }

    /**
     * The platform refused the call, or the platform's own error could not
     * be understood. Distinct from a validation error: the request was
     * well-formed and the network said no.
     */
    public static function platform(string $message, string $errorCode = 'PLATFORM_ERROR'): self
    {
        return new self($errorCode, $message, Response::HTTP_BAD_GATEWAY);
    }
}
