<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Aggregator\AggregatorPublisher;
use Spora\Plugins\Social\Aggregator\Zernio\ZernioTransportException;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialChannel;
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
        private readonly AggregatorPublisher $aggregator,
        private readonly PostMedia $postMedia,
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
     *
     * A target still in `publishing` is a publish whose outcome is not
     * known: the post is going out, not refused. `failed` means the
     * platform answered and said no, and a post row that says otherwise
     * invites the operator to publish it a second time.
     */
    public function deriveStatus(SocialPost $post): string
    {
        $targets = $post->targets()->get();

        if ($targets->isEmpty()) {
            return SocialPost::STATUS_DRAFT;
        }

        if ($this->countWithStatus($targets, SocialPostTarget::STATUS_PUBLISHING) > 0) {
            return SocialPost::STATUS_PUBLISHING;
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

        /** @var Collection<int, SocialPostTarget> $targets */
        $targets = $post->targets()->get();

        // A draft may have no account, and it cannot go out without one:
        // the targets are the platforms, and there is nothing to publish
        // to. Refused here rather than left to publish nothing, which
        // would read as a post that went out.
        if ($targets->isEmpty()) {
            throw SocialException::validation('This post has no account, so there is nothing to publish. Pick one for it with `social_post` action `update`, or connect one with `social_connections`.');
        }

        $post->status = SocialPost::STATUS_PUBLISHING;
        $post->save();

        $direct = [];
        $aggregated = [];

        foreach ($targets as $target) {
            if ($onlyFailed && $target->status !== SocialPostTarget::STATUS_FAILED) {
                continue;
            }

            if (!$onlyFailed && $this->isSettled($target)) {
                continue;
            }

            // The plugin's own networks publish one target at a time, as
            // they always did. The aggregator's targets go out together:
            // one cross-post, one call.
            if ($target->channel()->isDirect()) {
                $direct[] = $target;

                continue;
            }

            $aggregated[] = $target;
        }

        foreach ($direct as $target) {
            $this->publishTarget($post, $target);
        }

        if ($aggregated !== []) {
            $this->publishAggregatorGroup($post, $aggregated);
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
     * Whether the target is not to be published again.
     *
     * A published target is done. A target in `publishing` that carries
     * an idempotency key is a publish that started and never finished:
     * the reconciliation owns it and replays it under the key it went
     * out with. A second attempt here would mint a **new** key, and a
     * new key is a new post — a double-publish.
     *
     * A target in `publishing` with no key is a different case: it was
     * never given one, so there is nothing to replay, and it is
     * publishable.
     */
    private function isSettled(SocialPostTarget $target): bool
    {
        if ($target->status === SocialPostTarget::STATUS_PUBLISHED) {
            return true;
        }

        return $target->status === SocialPostTarget::STATUS_PUBLISHING
            && (string) $target->idempotency_key !== '';
    }

    /**
     * The aggregator's targets, in one call.
     *
     * The key is minted once for the attempt and written to every target
     * of the group **before** the call: that is what makes a crash
     * survivable, because the targets that are still `publishing` carry
     * the key that was used and the replay uses it again.
     *
     * @param list<SocialPostTarget> $targets
     */
    private function publishAggregatorGroup(SocialPost $post, array $targets): void
    {
        try {
            $media = $this->originalMedia($post);
        } catch (Throwable $e) {
            // The attachment cannot be read: every target of the group
            // fails with the same reason, the way the native path fails
            // one target and leaves the others alone.
            foreach ($targets as $target) {
                $this->failTarget($target, $e->getMessage());
            }

            return;
        }

        $going = [];

        foreach ($targets as $target) {
            $channel = $target->channel();

            $bodyFit = PlatformFit::checkBody($channel, $this->bodyFor($post, $channel));
            if (!$bodyFit['fits']) {
                $this->failTarget($target, trim(
                    implode(' ', $bodyFit['reasons']) . ' ' . (string) $bodyFit['remedy'],
                ));

                continue;
            }

            $mediaProblem = $this->mediaProblem($channel, $media);
            if ($mediaProblem !== null) {
                $this->failTarget($target, $mediaProblem);

                continue;
            }

            $going[] = $target;
        }

        if ($going === []) {
            return;
        }

        $key = $this->idempotencyKey();

        foreach ($going as $target) {
            $target->status = SocialPostTarget::STATUS_PUBLISHING;
            $target->idempotency_key = $key;
            $target->error = null;
            $target->save();
        }

        try {
            $outcomes = $this->aggregator->publish($post, $going, $key, $media);
        } catch (ZernioTransportException $e) {
            // The call never landed, or its answer never came back. The
            // publish may still have happened, so the targets stay in
            // `publishing` with their key: a `failed` would invite a
            // retry, and a retry mints a new key — which is a new post,
            // which is a double-publish.
            return;
        } catch (Throwable $e) {
            foreach ($going as $target) {
                $this->failTarget($target, $e->getMessage());
            }

            return;
        }

        $this->aggregator->write($going, $outcomes);
    }

    /**
     * The attachment as it is.
     */
    private function originalMedia(SocialPost $post): ?PreparedMedia
    {
        return $this->postMedia->original($post);
    }

    /**
     * Why the attachment does not fit the channel, when it does not.
     */
    private function mediaProblem(SocialChannel $channel, ?PreparedMedia $media): ?string
    {
        if ($media === null) {
            $rule = PlatformFit::rule($channel);

            if (!$rule['media_required']) {
                return null;
            }

            return $rule['label'] . ' cannot publish a post without media. ' . PlatformFit::REMEDY_ATTACH_MEDIA . '.';
        }

        $fit = PlatformFit::check($channel, $media->mimeType, $media->sizeBytes);

        if ($fit['fits']) {
            return null;
        }

        return trim(implode(' ', $fit['reasons']) . ' ' . (string) $fit['remedy']);
    }

    /**
     * The key the group's publish goes out under.
     *
     * A version-4 UUID: the aggregator's window is 24 hours, and the
     * key has to be new for every attempt so a retry is a new post.
     */
    private function idempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * The target failed, and why.
     */
    private function failTarget(SocialPostTarget $target, string $error): void
    {
        $target->status = SocialPostTarget::STATUS_FAILED;
        $target->error = $error;
        $target->save();
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
            $channel = $target->channel();

            // The adapter is resolved first: a channel nothing can
            // publish is a different failure from a body that is too
            // long, and it is the one the operator has to act on.
            $publisher = $this->publishers->for($channel);

            $body = $this->bodyFor($post, $channel);

            // The text is judged against the channel it is going to, with
            // that channel's own body: one post aimed at Instagram and
            // Facebook carries two bodies, and each has its own limit. The
            // hashtags are part of the text that goes out, so they are
            // counted against the limit as well.
            $bodyFit = PlatformFit::checkBody($channel, $body);
            if (!$bodyFit['fits']) {
                throw SocialException::validation(trim(
                    implode(' ', $bodyFit['reasons']) . ' ' . (string) $bodyFit['remedy'],
                ));
            }

            $result = $publisher->publish(new PublishRequest(
                externalId: (string) $account->external_id,
                kind: SocialAccountKind::from((string) $account->kind),
                accessToken: $this->connections->tokenFor($account),
                body: $body,
                media: $this->prepareMedia($post, $channel),
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

            $channel = $target->channel();

            // The aggregator takes the post down itself, and it needs the
            // account id: the endpoint refuses a post that reached
            // several accounts of one network with `409
            // ambiguous_account`, and the operator must never see that.
            if (!$channel->isDirect()) {
                $error = $this->aggregator->unpublish($target);

                if ($error !== null) {
                    throw SocialException::platform($error, 'PLATFORM_REQUEST_FAILED');
                }

                $target->status = SocialPostTarget::STATUS_DELETED;
                $target->error = null;
                $target->save();

                return;
            }

            $publisher = $this->publishers->for($channel);

            if ($target->external_id === null || $target->external_id === '') {
                throw SocialException::conflict('This target has no published id to delete.');
            }

            $result = $publisher->delete(new DeleteRequest(
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
     * The attachment the channel will receive, checked against that
     * channel's rules.
     *
     * The check runs on the prepared media, not on the original: for
     * Instagram the prepared media is the JPEG derivative, which is what
     * the platform will actually see.
     */
    private function prepareMedia(SocialPost $post, SocialChannel $channel): ?PublishMedia
    {
        $attachment = $post->media()->orderBy('position')->first();

        if ($attachment === null) {
            $rule = PlatformFit::rule($channel);

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
        $prepared = $this->media->prepare($asset, $channel);

        $fit = PlatformFit::check($channel, $prepared->mimeType, $prepared->sizeBytes);
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

    private function bodyFor(SocialPost $post, SocialChannel $channel): string
    {
        return (new PostBodies($post))->for($channel);
    }
}
