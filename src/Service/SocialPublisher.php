<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Publish\DeleteRequest;
use Spora\Plugins\Social\Publish\PublisherRegistry;
use Spora\Plugins\Social\Publish\PublishMedia;
use Spora\Plugins\Social\Publish\PublishRequest;
use Spora\Plugins\Social\Publish\PublishResult;
use Throwable;

/**
 * Sending a post to the networks.
 *
 * A post's outcome is derived from its targets: `published` when every
 * target published, `partial` when some did. The status column itself is
 * written — `publishing` while the post goes out — but only the outcome is
 * derived, so a post-level status can never claim a publish that did not
 * happen.
 *
 * One target failing does not stop the others — the whole point of the
 * per-target rows is that a post can succeed on one network and fail on
 * another.
 */
final class SocialPublisher
{
    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly SocialPostService $posts,
        private readonly SocialConnectionService $connections,
        private readonly MediaPipeline $media,
        private readonly PublisherRegistry $publishers,
    ) {}

    /**
     * Publish the post to every target that has not published yet.
     */
    public function publish(int $postId, int $principalId): SocialPost
    {
        return $this->runPublish($postId, $principalId, false);
    }

    /**
     * Publish only the targets that failed last time.
     */
    public function retry(int $postId, int $principalId): SocialPost
    {
        return $this->runPublish($postId, $principalId, true);
    }

    /**
     * Take a published post back down, target by target.
     *
     * The delete is per target because the publish was: a post that
     * reached LinkedIn and not Instagram takes down the LinkedIn one, and
     * Instagram says it cannot delete at all.
     */
    public function delete(int $postId, int $principalId): SocialPost
    {
        $post = $this->posts->find($postId, $principalId);

        /** @var Collection<int, SocialPostTarget> $targets */
        $targets = $post->targets()->get();

        foreach ($targets as $target) {
            if ($target->status !== SocialPostTarget::STATUS_PUBLISHED) {
                continue;
            }

            $this->deleteTarget($target);
        }

        $post = $post->fresh() ?? $post;
        $post->status = $this->deriveStatus($post);
        $post->save();

        return $post;
    }

    /**
     * The status a post's targets imply.
     *
     * Published when every target published, partial when some did,
     * failed when none did. A target that was taken back down is not a
     * failure — the post went out and came back — so a post with nothing
     * left on any network is cancelled rather than failed.
     */
    public function deriveStatus(SocialPost $post): string
    {
        $targets = $post->targets()->get();

        if ($targets->isEmpty()) {
            return SocialPost::STATUS_DRAFT;
        }

        $published = $this->countWithStatus($targets, SocialPostTarget::STATUS_PUBLISHED);

        if ($published > 0) {
            return $this->publishedStatus($published, $targets->count());
        }

        return $this->unpublishedStatus($targets);
    }

    /**
     * The status of a post with at least one published target.
     */
    private function publishedStatus(int $published, int $total): string
    {
        return $published === $total ? SocialPost::STATUS_PUBLISHED : SocialPost::STATUS_PARTIAL;
    }

    /**
     * The status of a post with nothing published.
     *
     * A target that was taken back down is not a failure — the post went
     * out and came back — so a post with nothing left on any network is
     * cancelled rather than failed.
     */
    private function unpublishedStatus(Collection $targets): string
    {
        $takenDown = $this->countWithStatus($targets, SocialPostTarget::STATUS_DELETED);

        return $takenDown === $targets->count() ? SocialPost::STATUS_CANCELLED : SocialPost::STATUS_FAILED;
    }

    /**
     * How many targets carry a status.
     *
     * @param Collection<int, SocialPostTarget> $targets
     */
    private function countWithStatus(Collection $targets, string $status): int
    {
        return $targets->filter(
            static fn(SocialPostTarget $t): bool => $t->status === $status,
        )->count();
    }

    /**
     * Take ownership of a scheduled post whose time has come.
     *
     * The transition is atomic: the row leaves `scheduled` under a row
     * lock, so two workers on the same install cannot publish the same post
     * twice — only the winner gets the post back.
     *
     * A post with no usable `scheduled_for` is never claimed: there is no
     * time to compare it against, and claiming it would publish a post the
     * operator never dated.
     */
    public function claimScheduled(int $postId): ?SocialPost
    {
        $now = gmdate(self::DB_DATETIME_FORMAT);

        return Capsule::connection()->transaction(function () use ($postId, $now): ?SocialPost {
            $row = Capsule::table('social_posts')
                ->where('id', $postId)
                ->where('status', SocialPost::STATUS_SCHEDULED)
                ->whereNotNull('scheduled_for')
                ->where('scheduled_for', '<=', $now)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            Capsule::table('social_posts')
                ->where('id', $postId)
                ->update([
                    'status'     => SocialPost::STATUS_PUBLISHING,
                    'updated_at' => $now,
                ]);

            return SocialPost::query()->find($postId);
        });
    }

    /**
     * Give up on a post that could not be published at all.
     *
     * Without this a post that was claimed and then hit an error would sit
     * in `publishing` with nothing left to publish it.
     */
    public function markFailed(int $postId): void
    {
        Capsule::table('social_posts')
            ->where('id', $postId)
            ->where('status', SocialPost::STATUS_PUBLISHING)
            ->update([
                'status'     => SocialPost::STATUS_FAILED,
                'updated_at' => gmdate(self::DB_DATETIME_FORMAT),
            ]);
    }

    private function runPublish(int $postId, int $principalId, bool $onlyFailed): SocialPost
    {
        $post = $this->posts->find($postId, $principalId);

        $post->status = SocialPost::STATUS_PUBLISHING;
        $post->save();

        /** @var Collection<int, SocialPostTarget> $targets */
        $targets = $post->targets()->get();

        foreach ($targets as $target) {
            if ($onlyFailed && $target->status !== SocialPostTarget::STATUS_FAILED) {
                continue;
            }

            if (!$onlyFailed && $target->status === SocialPostTarget::STATUS_PUBLISHED) {
                continue;
            }

            $this->publishTarget($post, $target);
        }

        $post = $post->fresh() ?? $post;
        $post->status = $this->deriveStatus($post);

        if ($post->status === SocialPost::STATUS_PUBLISHED || $post->status === SocialPost::STATUS_PARTIAL) {
            $post->published_at = Carbon::now();
        }

        $post->save();

        return $post;
    }

    /**
     * Publish one target.
     *
     * The failure is written on the target, not thrown: the other targets
     * still have to be tried, and a post that succeeded on one network and
     * failed on another is a normal outcome.
     */
    private function publishTarget(SocialPost $post, SocialPostTarget $target): void
    {
        $target->status = SocialPostTarget::STATUS_PUBLISHING;
        $target->error = null;
        $target->save();

        try {
            $account = $this->connections->findAccount((int) $target->account_id, (int) $post->principal_id);
            $platform = SocialPlatform::tryFromName((string) $target->platform);

            if ($platform === null) {
                throw SocialException::validation('This target has an unknown platform.');
            }

            $body = $this->bodyFor($post, $platform);

            // The body is judged against the platform it is going to, with
            // that platform's own text: one post aimed at Instagram and
            // Facebook carries two bodies, and each has its own limit.
            $bodyFit = PlatformFit::checkBody($platform, $body);
            if (!$bodyFit['fits']) {
                throw SocialException::validation(trim(
                    implode(' ', $bodyFit['reasons']) . ' ' . (string) $bodyFit['remedy'],
                ));
            }

            $result = $this->publishers->for($platform)->publish(new PublishRequest(
                externalId: (string) $account->external_id,
                kind: SocialAccountKind::from((string) $account->kind),
                accessToken: $this->connections->tokenFor($account),
                body: $body,
                media: $this->prepareMedia($post, $platform),
                // The container is written down as soon as the platform has
                // one, because the target is in `publishing` until the
                // publish is done — and a publish that dies here leaves a
                // container behind that only the reconciliation can resolve.
                onContainer: function (string $containerId) use ($target): void {
                    $target->container_id = $containerId;
                    $target->save();
                },
            ));

            $this->recordResult($target, $result);
        } catch (Throwable $e) {
            $target->status = SocialPostTarget::STATUS_FAILED;
            $target->error = $e->getMessage();
            $target->save();
        }
    }

    private function recordResult(SocialPostTarget $target, PublishResult $result): void
    {
        // The container id is kept whether the publish worked or not: a
        // publish that half-finished leaves a container behind, and
        // {@see \Spora\Plugins\Social\Service\ContainerReconciliation} asks
        // the container how it went.
        if ($result->containerId !== null) {
            $target->container_id = $result->containerId;
        }

        if ($result->success) {
            $target->status = SocialPostTarget::STATUS_PUBLISHED;
            $target->external_id = $result->externalId;
            $target->external_url = $result->externalUrl;
            $target->error = null;
            $target->published_at = Carbon::now();
            $target->save();

            return;
        }

        $target->status = SocialPostTarget::STATUS_FAILED;
        $target->error = $result->error ?? 'The platform refused the post.';
        $target->save();
    }

    /**
     * Take one target's post back down.
     *
     * A refusal is written on the target and the target keeps its
     * published status: the post is still on the network, and a status of
     * failed would claim it is not.
     */
    private function deleteTarget(SocialPostTarget $target): void
    {
        try {
            $post = $target->post;
            $account = $target->account;

            if ($post === null || $account === null) {
                throw SocialException::conflict('This target has no account to delete with.');
            }

            $platform = SocialPlatform::tryFromName((string) $target->platform);

            if ($platform === null) {
                throw SocialException::validation('This target has an unknown platform.');
            }

            if ($target->external_id === null || $target->external_id === '') {
                throw SocialException::conflict('This target has no published id to delete.');
            }

            $result = $this->publishers->for($platform)->delete(new DeleteRequest(
                externalId: (string) $target->external_id,
                kind: SocialAccountKind::from((string) $account->kind),
                accessToken: $this->connections->tokenFor($account),
            ));

            if (!$result->success) {
                throw SocialException::platform(
                    $result->error ?? 'The platform refused to delete the post.',
                    'PLATFORM_REQUEST_FAILED',
                );
            }

            $target->status = SocialPostTarget::STATUS_DELETED;
            $target->error = null;
            $target->save();
        } catch (Throwable $e) {
            $target->error = $e->getMessage();
            $target->save();
        }
    }

    /**
     * The attachment the platform will receive, checked against that
     * platform's rules.
     *
     * The check runs on the prepared media, not on the original: for
     * Instagram the prepared media is the JPEG derivative, which is what
     * the platform will actually see.
     */
    private function prepareMedia(SocialPost $post, SocialPlatform $platform): ?PublishMedia
    {
        $attachment = $post->media()->orderBy('position')->first();

        if ($attachment === null) {
            $rule = PlatformFit::rule($platform);

            if ($rule['media_required']) {
                throw SocialException::validation(
                    $rule['label'] . ' cannot publish a post without media. ' . PlatformFit::REMEDY_ATTACH_MEDIA . '.',
                );
            }

            return null;
        }

        // One attachment per post, and this is the last gate: a post that
        // carries a second file would publish the first and leave the
        // second behind, which is the thing the operator cannot see.
        if ($post->media()->count() > PlatformFit::MEDIA_PER_POST) {
            throw SocialException::validation(
                'A post carries one attachment. ' . PlatformFit::REMEDY_ONE_ATTACHMENT . '.',
            );
        }

        $asset = $this->media->findAsset((string) $attachment->media_asset_id, (int) $post->principal_id);
        $prepared = $this->media->prepare($asset, $platform);

        $fit = PlatformFit::check($platform, $prepared->mimeType, $prepared->sizeBytes);
        if (!$fit['fits']) {
            throw SocialException::validation(trim(
                implode(' ', $fit['reasons']) . ' ' . (string) $fit['remedy'],
            ));
        }

        return new PublishMedia(
            mimeType: $prepared->mimeType,
            bytes: $prepared->bytes,
            publicUrl: $prepared->publicUrl,
            filename: $prepared->filename,
        );
    }

    private function bodyFor(SocialPost $post, SocialPlatform $platform): string
    {
        $bodies = $post->platform_bodies;

        if (is_array($bodies)) {
            $specific = $bodies[$platform->value] ?? null;
            if (is_string($specific) && $specific !== '') {
                return $specific;
            }
        }

        return (string) $post->body;
    }
}
