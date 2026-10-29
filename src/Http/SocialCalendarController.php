<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Http;

use Spora\Auth\AuthService;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Service\SocialPostService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The calendar: the posts of a month.
 *
 * There is no slot in here any more. The fixed slot published the same
 * words every week — a template mechanism with no varying template — and
 * LinkedIn and Meta ship native scheduled posts now, so a third-party
 * static repeater competed on the weakest axis available. The agent-driven
 * mode replaces it: core's scheduler wakes an agent, the agent writes the
 * copy, and the post lands here like any other.
 */
final class SocialCalendarController extends AbstractSocialController
{
    public function __construct(
        AuthService $auth,
        PrincipalResolver $principals,
        PrincipalService $principalService,
        private readonly SocialPostService $posts,
    ) {
        parent::__construct($auth, $principals, $principalService);
    }

    public function calendar(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->readablePrincipalId($request);

            $month = (string) ($request->query->get('month') ?? gmdate('Y-m'));
            $month = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : gmdate('Y-m');

            $posts = $this->posts->postsOfMonth($principalId, $month);

            return $this->ok([
                'posts' => $this->postsOf($posts['items']),
                'total' => $posts['total'],
            ]);
        });
    }

    /**
     * The posts, with the day each one is due or went out on.
     *
     * @param  list<SocialPost> $items
     * @return list<array<string, mixed>>
     */
    private function postsOf(array $items): array
    {
        $posts = [];
        foreach ($items as $post) {
            $posts[] = [
                'post_id'       => (int) $post->id,
                'title'         => $post->title,
                'status'        => (string) $post->status,
                'scheduled_for' => $post->scheduled_for?->toIso8601String(),
                'published_at'  => $post->published_at?->toIso8601String(),
            ];
        }

        return $posts;
    }
}
