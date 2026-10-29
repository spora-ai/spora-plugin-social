<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Scheduling;

use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Service\ContainerReconciliation;
use Spora\Plugins\Social\Service\DateTimeParser;
use Spora\Plugins\Social\Service\SocialMetricsService;
use Spora\Plugins\Social\Service\SocialPublisher;
use Spora\Scheduling\DueWork;
use Spora\Scheduling\DueWorkProviderInterface;
use Throwable;

/**
 * The plugin's due work: the posts whose time has come, the readings that
 * are due, and the publishes that half-finished.
 *
 * There is no slot kind any more. The fixed slot published the same words
 * every week — a template mechanism with no varying template — and the
 * platforms ship native scheduled posts now, so it competed on the weakest
 * axis available. The agent-driven mode replaced it: core's scheduler
 * wakes an agent, the agent writes the copy, and the post arrives here
 * like any other.
 *
 * A failure on one item must not stop the rest, so every failure is
 * caught here and written down rather than thrown at the worker.
 */
final class SocialDueWorkProvider implements DueWorkProviderInterface
{
    /**
     * The three kinds of work this provider produces.
     */
    public const KIND_POST = 'post';
    public const KIND_METRIC = 'metric';
    public const KIND_CONTAINER = 'container';

    /**
     * How long after a publish the first reading happens, and how often
     * after that.
     */
    private const FIRST_READING_DELAY_HOURS = 2;
    private const READING_INTERVAL_DAYS = 1;

    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly SocialMetricsService $metrics,
        private readonly SocialPublisher $publisher,
        private readonly ContainerReconciliation $reconciliation,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param  list<int> $principalIds
     * @return list<DueWork>
     */
    public function dueWork(array $principalIds): array
    {
        if ($principalIds === []) {
            return [];
        }

        $work = [];

        foreach ($this->duePosts($principalIds) as $post) {
            $work[] = $this->unit(
                (int) $post->principal_id,
                $post->scheduled_for?->format(self::DB_DATETIME_FORMAT) ?? gmdate(self::DB_DATETIME_FORMAT),
                ['kind' => self::KIND_POST, 'post_id' => (int) $post->id],
            );
        }

        foreach ($this->dueMetrics($principalIds) as $target) {
            $post = $target->post;

            $work[] = $this->unit(
                $post instanceof SocialPost ? (int) $post->principal_id : 0,
                gmdate(self::DB_DATETIME_FORMAT),
                ['kind' => self::KIND_METRIC, 'target_id' => (int) $target->id],
            );
        }

        foreach ($this->reconciliation->dueTargets($principalIds) as $target) {
            $post = $target->post;

            $work[] = $this->unit(
                $post instanceof SocialPost ? (int) $post->principal_id : 0,
                gmdate(self::DB_DATETIME_FORMAT),
                ['kind' => self::KIND_CONTAINER, 'target_id' => (int) $target->id],
            );
        }

        return $work;
    }

    public function execute(DueWork $work): void
    {
        $kind = (string) ($work->payload['kind'] ?? '');

        try {
            match ($kind) {
                self::KIND_POST     => $this->runPost((int) ($work->payload['post_id'] ?? 0)),
                self::KIND_METRIC   => $this->runMetric((int) ($work->payload['target_id'] ?? 0)),
                self::KIND_CONTAINER => $this->runContainer((int) ($work->payload['target_id'] ?? 0)),
                default             => null,
            };
        } catch (Throwable $e) {
            // One item failing must not stop the rest of the work list.
            $this->logger->error('Social due work failed', [
                'kind'            => $kind,
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish the scheduled post — once.
     *
     * The claim and the publish are not one transaction: a publish does
     * network I/O, and holding a row lock across that would block every
     * other worker on the table.
     */
    private function runPost(int $postId): void
    {
        if ($postId <= 0) {
            return;
        }

        $claimed = $this->publisher->claimScheduled($postId);
        if ($claimed === null) {
            return;
        }

        try {
            $this->publisher->publish($postId, (int) $claimed->principal_id);
        } catch (Throwable $e) {
            // The claim already moved the post to `publishing`; without this
            // it would sit there with nothing left to publish it.
            $this->publisher->markFailed($postId);

            throw $e;
        }
    }

    private function runMetric(int $targetId): void
    {
        if ($targetId <= 0) {
            return;
        }

        $target = SocialPostTarget::query()->with('post')->find($targetId);
        if ($target === null) {
            return;
        }

        $this->metrics->collect($target);
    }

    /**
     * Ask the container how the publish went.
     *
     * The post's own status is derived from its targets, so a target that
     * resolved moves the post with it.
     */
    private function runContainer(int $targetId): void
    {
        if ($targetId <= 0) {
            return;
        }

        $target = SocialPostTarget::query()->with('post')->find($targetId);
        if ($target === null) {
            return;
        }

        if (!$this->reconciliation->resolve($target)) {
            return;
        }

        $post = $target->post;
        if (!$post instanceof SocialPost) {
            return;
        }

        $post->status = $this->publisher->deriveStatus($post);

        if ($post->status === SocialPost::STATUS_PUBLISHED || $post->status === SocialPost::STATUS_PARTIAL) {
            $post->published_at = Carbon::now();
        }

        $post->save();
    }

    /**
     * The scheduled posts whose time has come.
     *
     * A post without a usable `scheduled_for` is not selected: there is no
     * time to compare it against, and claiming it would publish a post the
     * operator never dated.
     *
     * @param  list<int> $principalIds
     * @return list<SocialPost>
     */
    private function duePosts(array $principalIds): array
    {
        return SocialPost::query()
            ->whereIn('principal_id', $principalIds)
            ->where('status', SocialPost::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', gmdate(self::DB_DATETIME_FORMAT))
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * The published targets whose numbers are due.
     *
     * @param  list<int> $principalIds
     * @return list<SocialPostTarget>
     */
    private function dueMetrics(array $principalIds): array
    {
        $firstReadingBefore = DateTimeParser::nowUtc()
            ->subHours(self::FIRST_READING_DELAY_HOURS)
            ->format(self::DB_DATETIME_FORMAT);

        $lastReadingBefore = DateTimeParser::nowUtc()
            ->subDays(self::READING_INTERVAL_DAYS)
            ->format('Y-m-d');

        /** @var list<SocialPostTarget> $targets */
        $targets = SocialPostTarget::query()
            ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.post_id')
            ->leftJoin('social_post_metrics', 'social_post_metrics.target_id', '=', 'social_post_targets.id')
            ->whereIn('social_posts.principal_id', $principalIds)
            ->where('social_post_targets.status', SocialPostTarget::STATUS_PUBLISHED)
            ->whereNotNull('social_post_targets.external_id')
            ->where('social_post_targets.published_at', '<=', $firstReadingBefore)
            ->where(function ($query) use ($lastReadingBefore): void {
                $query->whereNull('social_post_metrics.id')
                    ->orWhere('social_post_metrics.metric_date', '<', $lastReadingBefore);
            })
            ->select('social_post_targets.*')
            ->get()
            ->all();

        return $targets;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function unit(int $principalId, string $dueAt, array $payload): DueWork
    {
        return new DueWork(
            provider: self::class,
            principalId: $principalId,
            payload: $payload,
            dueAt: $dueAt,
        );
    }
}
