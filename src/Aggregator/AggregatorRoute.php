<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

use Spora\Plugins\Social\Aggregator\Zernio\ZernioTransportException;
use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Service\PlatformFit;
use Spora\Plugins\Social\Service\PostBodies;
use Spora\Plugins\Social\Service\PostMedia;
use Spora\Plugins\Social\Service\PreparedMedia;
use Throwable;

/**
 * The aggregator's route: the networks Spora reaches through somebody
 * else's API.
 *
 * The direct path and the aggregator path are two different jobs that
 * happened to share a publisher. The plugin's own networks publish one
 * target at a time; the aggregator's targets go out **together**, as one
 * cross-post in one call.
 *
 * **The group goes out under one key.** The key is minted once for the
 * attempt and written to every target of the group **before** the call:
 * that is what makes a crash survivable, because the targets that are
 * still `publishing` carry the key that was used and the replay uses it
 * again.
 */
final class AggregatorRoute
{
    public function __construct(
        private readonly AggregatorPublisher $aggregator,
        private readonly PostMedia $postMedia,
    ) {}

    /**
     * The group's targets, in one call.
     *
     * @param list<SocialPostTarget> $targets
     */
    public function publish(SocialPost $post, array $targets): void
    {
        try {
            $media = $this->postMedia->original($post);
        } catch (Throwable $e) {
            // The attachment cannot be read: every target of the group
            // fails with the same reason, the way the native path fails
            // one target and leaves the others alone.
            foreach ($targets as $target) {
                $this->fail($target, $e->getMessage());
            }

            return;
        }

        $going = [];

        foreach ($targets as $target) {
            $problem = $this->fitProblem($post, $target, $media);

            if ($problem !== null) {
                $this->fail($target, $problem);

                continue;
            }

            $going[] = $target;
        }

        if ($going === []) {
            return;
        }

        $this->send($post, $going, $media);
    }

    /**
     * Take one target's post back down.
     *
     * The account id travels with the platform: the endpoint answers
     * `409 ambiguous_account` when a post reached several accounts of
     * one network and no account was named, and the operator must never
     * see that.
     *
     * @return string|null the reason it could not be taken down
     */
    public function unpublish(SocialPostTarget $target): ?string
    {
        $error = $this->aggregator->unpublish($target);

        if ($error !== null) {
            return $error;
        }

        $target->status = SocialPostTarget::STATUS_DELETED;
        $target->error = null;
        $target->save();

        return null;
    }

    /**
     * The group, under one key.
     *
     * @param list<SocialPostTarget> $targets
     */
    private function send(SocialPost $post, array $targets, ?PreparedMedia $media): void
    {
        $key = $this->idempotencyKey();

        foreach ($targets as $target) {
            $target->status = SocialPostTarget::STATUS_PUBLISHING;
            $target->idempotency_key = $key;
            $target->error = null;
            $target->save();
        }

        try {
            $outcomes = $this->aggregator->publish($post, $targets, $key, $media);
        } catch (ZernioTransportException) {
            // The call never landed, or its answer never came back. The
            // publish may still have happened, so the targets stay in
            // `publishing` with their key: a `failed` would invite a
            // retry, and a retry mints a new key — which is a new post,
            // which is a double-publish.
            return;
        } catch (Throwable $e) {
            foreach ($targets as $target) {
                $this->fail($target, $e->getMessage());
            }

            return;
        }

        $this->aggregator->write($targets, $outcomes);
    }

    /**
     * Why the target does not go out, when it does not.
     *
     * A channel the plugin has no rules for is a refusal of that target
     * alone. The rules table throws for it, and the exception must not
     * take the rest of the group down with it — and it must not escape
     * before the key is written, or the survivors are lost to the
     * reconciliation as well.
     */
    private function fitProblem(SocialPost $post, SocialPostTarget $target, ?PreparedMedia $media): ?string
    {
        try {
            $channel = $target->channel();

            $bodyFit = PlatformFit::checkBody($channel, $this->bodyFor($post, $channel));
            if (!$bodyFit['fits']) {
                return trim(implode(' ', $bodyFit['reasons']) . ' ' . (string) $bodyFit['remedy']);
            }

            return $this->mediaProblem($channel, $media);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Why the attachment does not fit the channel, when it does not.
     */
    private function mediaProblem(SocialChannel $channel, ?PreparedMedia $media): ?string
    {
        if ($media === null) {
            return $this->missingMediaProblem($channel);
        }

        $fit = PlatformFit::check($channel, $media->mimeType, $media->sizeBytes);

        return $fit['fits']
            ? null
            : trim(implode(' ', $fit['reasons']) . ' ' . (string) $fit['remedy']);
    }

    /**
     * Why the channel will not take a post without media.
     */
    private function missingMediaProblem(SocialChannel $channel): ?string
    {
        $rule = PlatformFit::rule($channel);

        if (!$rule['media_required']) {
            return null;
        }

        return $rule['label'] . ' cannot publish a post without media. ' . PlatformFit::REMEDY_ATTACH_MEDIA . '.';
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
    private function fail(SocialPostTarget $target, string $error): void
    {
        $target->status = SocialPostTarget::STATUS_FAILED;
        $target->error = $error;
        $target->save();
    }

    private function bodyFor(SocialPost $post, SocialChannel $channel): string
    {
        return (new PostBodies($post))->for($channel);
    }
}
