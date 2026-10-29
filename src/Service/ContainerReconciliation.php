<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Publish\InstagramAdapter;
use Spora\Plugins\Social\Publish\PlatformApi;

/**
 * The publishes that half-finished.
 *
 * Instagram creates a container and publishes it in a second call, so there
 * is a window in which the container exists and the publish has not
 * happened. A worker that dies in that window leaves a target in
 * `publishing` with a container id — and the container answers what became
 * of it: `GET /{container}?fields=status_code`.
 *
 * The container expires after 24 hours, so the question is worth asking for
 * exactly that long; after that the platform says `EXPIRED` and the
 * publish is known to have failed.
 */
final class ContainerReconciliation
{
    /**
     * How long before an unresolved container is asked about again.
     *
     * The worker ticks several times a minute, and a container is not worth
     * that many calls: it expires after 24 hours, so a quarter of an hour
     * between the questions is still time enough to catch it.
     */
    private const RECONCILE_INTERVAL_MINUTES = 15;

    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly SocialConnectionService $connections,
        private readonly PlatformApi $api,
    ) {}

    /**
     * The targets a publish left behind.
     *
     * A target in `publishing` that carries a container id is a publish that
     * started and never finished: the container was created, and nobody
     * knows what became of it.
     *
     * @param  list<int> $principalIds
     * @return list<SocialPostTarget>
     */
    public function dueTargets(array $principalIds): array
    {
        $askedBefore = gmdate(self::DB_DATETIME_FORMAT, time() - (self::RECONCILE_INTERVAL_MINUTES * 60));

        /** @var list<SocialPostTarget> $targets */
        $targets = SocialPostTarget::query()
            ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.post_id')
            ->whereIn('social_posts.principal_id', $principalIds)
            ->where('social_post_targets.status', SocialPostTarget::STATUS_PUBLISHING)
            ->whereNotNull('social_post_targets.container_id')
            ->where('social_post_targets.updated_at', '<=', $askedBefore)
            ->select('social_post_targets.*')
            ->get()
            ->all();

        return $targets;
    }

    /**
     * Ask the container how the publish went, and write the answer down.
     *
     * The write only lands on a target that is still in `publishing`, so of
     * two workers that ask at the same time only one resolves it.
     *
     * @return bool whether the target changed
     */
    public function resolve(SocialPostTarget $target): bool
    {
        $containerId = $target->container_id;

        if ($containerId === null || $containerId === '') {
            return false;
        }

        $status = $this->statusOf($target, $containerId);

        if ($status === 'PUBLISHED') {
            return $this->markPublished($target, $containerId);
        }

        if ($status === 'ERROR' || $status === 'EXPIRED') {
            return $this->markFailed($target, $containerId, $status);
        }

        // `IN_PROGRESS` is a container still working and `FINISHED` is a
        // container that was never published. Neither is an answer, so the
        // target is left where it is and asked again later.
        $this->markUnresolved($target, $containerId);

        return false;
    }

    /**
     * The container's own status.
     *
     * Null when the platform could not be asked: a container that is still
     * there but unreadable is not a failure, and the next tick asks again.
     */
    private function statusOf(SocialPostTarget $target, string $containerId): ?string
    {
        $account = $target->account;

        if ($account === null) {
            return null;
        }

        $response = $this->api->http->request(
            'GET',
            $this->api->versions->graphUrl(ApiVersions::GRAPH_HOST, '/' . $containerId),
            [
                'query'   => ['fields' => 'status_code'],
                'headers' => ['Authorization' => 'Bearer ' . $this->connections->tokenFor($account)],
            ],
        );

        if (!$response->isSuccessful()) {
            return null;
        }

        $status = $response->body['status_code'] ?? null;

        return is_string($status) ? strtoupper($status) : null;
    }

    /**
     * The container went out: the post is published.
     *
     * The container becomes the media, so the id the platform gave the
     * container is the id of the published post.
     */
    private function markPublished(SocialPostTarget $target, string $containerId): bool
    {
        $updated = SocialPostTarget::query()
            ->where('id', $target->id)
            ->where('status', SocialPostTarget::STATUS_PUBLISHING)
            ->where('container_id', $containerId)
            ->update([
                'status'       => SocialPostTarget::STATUS_PUBLISHED,
                'external_id'  => $containerId,
                'external_url' => InstagramAdapter::POST_URL . $containerId,
                'error'        => null,
                'published_at' => gmdate(self::DB_DATETIME_FORMAT),
            ]);

        return $updated > 0;
    }

    /**
     * The container did not go out: the publish failed, and the platform's
     * own status is the reason.
     */
    private function markFailed(SocialPostTarget $target, string $containerId, string $status): bool
    {
        $updated = SocialPostTarget::query()
            ->where('id', $target->id)
            ->where('status', SocialPostTarget::STATUS_PUBLISHING)
            ->where('container_id', $containerId)
            ->update([
                'status' => SocialPostTarget::STATUS_FAILED,
                'error'  => 'Instagram did not finish the media: ' . $status . '.',
            ]);

        return $updated > 0;
    }

    /**
     * The container has not answered yet.
     *
     * The row is touched so the container is not asked about again on the
     * next tick, and nothing else is written: the publish is still out.
     */
    private function markUnresolved(SocialPostTarget $target, string $containerId): void
    {
        SocialPostTarget::query()
            ->where('id', $target->id)
            ->where('status', SocialPostTarget::STATUS_PUBLISHING)
            ->where('container_id', $containerId)
            ->update(['updated_at' => gmdate(self::DB_DATETIME_FORMAT)]);
    }
}
