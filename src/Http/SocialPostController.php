<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Http;

use Spora\Auth\AuthService;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Service\SocialMetricsService;
use Spora\Plugins\Social\Service\SocialPostService;
use Spora\Plugins\Social\Service\SocialPublisher;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The posts and their numbers.
 */
final class SocialPostController extends AbstractSocialController
{
    public function __construct(
        AuthService $auth,
        PrincipalResolver $principals,
        PrincipalService $principalService,
        private readonly SocialPostService $posts,
        private readonly SocialPublisher $publisher,
        private readonly SocialMetricsService $metrics,
    ) {
        parent::__construct($auth, $principals, $principalService);
    }

    public function posts(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);

            $result = $this->posts->listForPrincipal($principalId, [
                'status'     => $request->query->get('status'),
                'platform'   => $request->query->get('platform'),
                'account_id' => $request->query->get('account_id'),
                'from'       => $request->query->get('from'),
                'to'         => $request->query->get('to'),
                'limit'      => $request->query->get('limit'),
                'offset'     => $request->query->get('offset'),
            ]);

            return $this->ok([
                'items' => array_map(
                    static fn(SocialPost $post): array => SocialPostResource::toArray($post),
                    $result['items'],
                ),
                'total' => $result['total'],
            ]);
        });
    }

    public function createPost(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $body = $this->body($request);

            $post = $this->posts->create($principalId, $body);

            // `publish_now` is the one thing the CRUD half does not do: it
            // sends the post to a platform, which is the publisher's job.
            if (($body['publish_now'] ?? false) === true) {
                $post = $this->publisher->publish((int) $post->id, $principalId);
            }

            return $this->ok(SocialPostResource::toArray($post), Response::HTTP_CREATED);
        });
    }

    public function post(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);
            $post = $this->posts->find($this->id($request, 'id'), $principalId);

            return $this->ok(SocialPostResource::toArray($post));
        });
    }

    public function updatePost(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $post = $this->posts->update($this->id($request, 'id'), $principalId, $this->body($request));

            return $this->ok(SocialPostResource::toArray($post));
        });
    }

    public function deletePost(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $this->posts->delete($this->id($request, 'id'), $principalId);

            return $this->ok(['deleted' => true]);
        });
    }

    /**
     * Take a published post back down.
     *
     * The targets are deleted one by one, and the answer carries the
     * per-target outcome: Instagram cannot delete published media, and
     * that target says so rather than the whole call failing.
     */
    public function deletePublished(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $post = $this->publisher->delete($this->id($request, 'id'), $principalId);

            return $this->ok(SocialPostResource::toArray($post));
        });
    }

    public function publishPost(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $post = $this->publisher->publish($this->id($request, 'id'), $principalId);

            return $this->ok(SocialPostResource::toArray($post));
        });
    }

    public function retryPost(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $post = $this->publisher->retry($this->id($request, 'id'), $principalId);

            return $this->ok(SocialPostResource::toArray($post));
        });
    }

    public function analyticsSummary(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);
            $days = max(1, min(365, (int) ($request->query->get('days') ?? 30)));

            return $this->ok($this->metrics->summary($principalId, $days));
        });
    }

    public function postAnalytics(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);

            return $this->ok($this->metrics->postAnalytics($this->id($request, 'postId'), $principalId));
        });
    }
}
