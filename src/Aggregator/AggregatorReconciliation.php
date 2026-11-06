<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Service\PostMedia;

/**
 * The aggregator's publishes that half-finished.
 *
 * A target in `publishing` that carries an idempotency key is a publish
 * that started and never finished: the key was written down before the
 * call, and the call's outcome never came back.
 *
 * **The key is the answer.** Replaying the publish with the same key
 * makes the aggregator answer with the original post and its per-network
 * results, so nothing is published twice — and a publish that never
 * reached the aggregator goes out now.
 *
 * The container does this for Instagram, which creates a container and
 * publishes it in a second call. An aggregator's target has no
 * container: its key is what a publish leaves behind.
 */
final class AggregatorReconciliation
{
    /**
     * How long before an unresolved publish is asked about again.
     *
     * The worker ticks several times a minute, and a publish is not
     * worth that many calls: the aggregator's idempotency window is 24
     * hours, so a quarter of an hour between the questions is still time
     * enough to catch it.
     */
    private const RECONCILE_INTERVAL_MINUTES = 15;

    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly AggregatorPublisher $publisher,
        private readonly PostMedia $postMedia,
    ) {}

    /**
     * The targets a publish left behind.
     *
     * @param  list<int> $principalIds
     * @return list<SocialPostTarget>
     */
    public function dueTargets(array $principalIds): array
    {
        $askedBefore = gmdate(self::DB_DATETIME_FORMAT, time() - (self::RECONCILE_INTERVAL_MINUTES * 60));

        return array_values(SocialPostTarget::query()
            ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.post_id')
            ->whereIn('social_posts.principal_id', $principalIds)
            ->where('social_post_targets.status', SocialPostTarget::STATUS_PUBLISHING)
            ->whereNotNull('social_post_targets.idempotency_key')
            // An empty key is no key: there is nothing to replay with.
            ->where('social_post_targets.idempotency_key', '<>', '')
            ->where('social_post_targets.updated_at', '<=', $askedBefore)
            ->select('social_post_targets.*')
            ->get()
            ->all());
    }

    /**
     * Replay the publish with the key it went out under.
     *
     * The whole group is replayed, not the one target: the group went
     * out in one call, and the answer describes the whole of it.
     *
     * @return bool whether anything changed
     */
    public function resolve(SocialPostTarget $target): bool
    {
        $key = (string) $target->idempotency_key;
        $post = $target->post;

        if ($key === '' || !$post instanceof SocialPost) {
            return false;
        }

        $group = SocialPostTarget::query()
            ->where('post_id', (int) $target->post_id)
            ->where('idempotency_key', $key)
            ->get()
            ->all();

        if ($group === []) {
            return false;
        }

        $outcomes = $this->publisher->publish($post, $group, $key, $this->postMedia->original($post));

        return $this->publisher->write($group, $outcomes);
    }
}
