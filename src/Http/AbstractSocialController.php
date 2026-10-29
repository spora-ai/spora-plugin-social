<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared plumbing of the plugin's controllers: the envelope, the
 * principal scoping and the error mapping.
 */
abstract class AbstractSocialController
{
    public function __construct(
        protected readonly AuthService $auth,
        protected readonly PrincipalResolver $principals,
        protected readonly PrincipalService $principalService,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    protected function ok(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * Run an action and turn a failure into the host's error envelope.
     */
    protected function run(callable $handler): JsonResponse
    {
        try {
            return $handler();
        } catch (SocialException $e) {
            return new JsonResponse(
                ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]],
                $e->status,
            );
        }
    }

    /**
     * The principal a read is scoped to.
     *
     * A request without one is a 422 — never "every principal the caller
     * can see", because that is a different question than the one asked.
     */
    protected function readablePrincipalId(Request $request): int
    {
        $requested = $this->requestedPrincipalId($request);

        if ($requested === null) {
            throw SocialException::validation('principal_id is required.');
        }

        if (!in_array($requested, $this->principals->visiblePrincipalIds($this->userId()), true)) {
            throw SocialException::forbidden('You cannot act for this principal.');
        }

        return $requested;
    }

    /**
     * The principal a write is scoped to.
     *
     * Writing publishes under the principal's name, so it needs more than
     * membership: the caller has to control the principal.
     */
    protected function writablePrincipalId(Request $request): int
    {
        $requested = $this->requestedPrincipalId($request);

        if ($requested === null) {
            throw SocialException::validation('principal_id is required.');
        }

        if (!$this->principalService->callerControlsPrincipal($this->userId(), $requested)) {
            throw SocialException::forbidden('You cannot act for this principal.');
        }

        return $requested;
    }

    protected function requestedPrincipalId(Request $request): ?int
    {
        $raw = $request->query->get('principal_id');

        if ($raw === null || $raw === '') {
            $raw = $this->body($request)['principal_id'] ?? null;
        }

        if (!is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    protected function userId(): int
    {
        $userId = $this->auth->currentUserId();

        if ($userId === null) {
            throw SocialException::forbidden('Authentication required.');
        }

        return $userId;
    }

    protected function id(Request $request, string $attribute): int
    {
        $raw = $request->attributes->get($attribute);

        if (!is_numeric($raw)) {
            throw SocialException::notFound('The requested resource was not found.');
        }

        return (int) $raw;
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(Request $request): array
    {
        $content = $request->getContent();

        if ($content === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SocialException::validation('Request body must be valid JSON.');
        }

        return is_array($decoded) ? $decoded : [];
    }
}
