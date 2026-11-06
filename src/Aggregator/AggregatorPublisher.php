<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Aggregator\Zernio\ZernioClient;
use Spora\Plugins\Social\Aggregator\Zernio\ZernioResponse;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Service\PostBodies;
use Spora\Plugins\Social\Service\PreparedMedia;

/**
 * The aggregator's publish: one call for the whole group.
 *
 * The operator sees **one cross-post** in the aggregator, not a post per
 * network, so the targets go out in a single `POST /v1/posts` carrying
 * every one of them in `platforms[]`.
 *
 * The call is synchronous inside the request and it publishes to every
 * network it names, which is why the client waits longer than the
 * native path does.
 *
 * **The key is the plugin's, and it is reused.** One key per publish
 * attempt: a retry of that attempt answers with the original post
 * instead of publishing a second one, and that is what makes a crash
 * survivable.
 */
final class AggregatorPublisher
{
    public function __construct(
        private readonly AggregatorService $aggregators,
        private readonly ZernioClient $client,
    ) {}

    /**
     * The group's outcome, by target id.
     *
     * @param  list<SocialPostTarget> $targets
     * @return array<int, AggregatorOutcome>
     */
    public function publish(SocialPost $post, array $targets, string $idempotencyKey, ?PreparedMedia $media): array
    {
        $connection = $this->connectionOf($targets);
        $key = $this->aggregators->apiKey($connection);

        $body = $this->requestBody($post, $targets);

        if ($media !== null) {
            $body['mediaItems'] = [[
                'url'  => $this->upload($key, $media),
                'type' => str_starts_with($media->mimeType, 'video/') ? 'video' : 'image',
            ]];
        }

        $response = $this->client->createPost($key, $body, $idempotencyKey);

        return $this->outcomes($response, $targets);
    }

    /**
     * Take a published post back down.
     *
     * The account id travels with the platform: the endpoint answers
     * `409 ambiguous_account` when a post reached several accounts of
     * one network and no account was named, and the operator must never
     * see that.
     */
    public function unpublish(SocialPostTarget $target): ?string
    {
        $account = $target->account;

        if ($account === null || $account->connection === null) {
            return 'This target has no account to delete with.';
        }

        if ($target->external_id === null || $target->external_id === '') {
            return 'This target has no published id to delete.';
        }

        $key = $this->aggregators->apiKey($account->connection);

        $response = $this->client->unpublish(
            $key,
            (string) $target->external_id,
            (string) $target->platform,
            (string) $account->external_id,
        );

        if ($response->isSuccessful()) {
            return null;
        }

        return $response->errorMessage() ?? 'The aggregator refused to delete the post.';
    }

    /**
     * The request body: every target in `platforms[]`, and one body for
     * the lot.
     *
     * A channel whose copy differs carries it as `customContent`, so
     * the group still goes out as one call.
     *
     * @param  list<SocialPostTarget> $targets
     * @return array<string, mixed>
     */
    private function requestBody(SocialPost $post, array $targets): array
    {
        $bodies = new PostBodies($post);

        $platforms = [];

        foreach ($targets as $target) {
            $account = $target->account;

            if ($account === null) {
                continue;
            }

            $entry = [
                'platform'  => (string) $target->platform,
                'accountId' => (string) $account->external_id,
            ];

            $channel = $target->channel();

            if ($bodies->hasOwn($channel)) {
                $entry['customContent'] = $bodies->for($channel);
            }

            $platforms[] = $entry;
        }

        return [
            'platforms'  => $platforms,
            'content'    => $bodies->shared(),
            'publishNow' => true,
        ];
    }

    /**
     * The bytes, in the aggregator's own media library.
     *
     * The aggregator fetches the file from a public HTTPS URL, so the
     * plugin uploads the bytes to its media library and sends the URL
     * it answers with. One upload serves every network in the group.
     */
    private function upload(string $key, PreparedMedia $media): string
    {
        $response = $this->client->presign(
            $key,
            $media->filename,
            $media->mimeType,
            $media->sizeBytes,
        );

        if (!$response->isSuccessful()) {
            throw SocialException::platform(
                $response->errorMessage() ?? 'The aggregator refused the upload.',
                'AGGREGATOR_REQUEST_FAILED',
            );
        }

        $uploadUrl = (string) ($response->body['uploadUrl'] ?? '');
        $publicUrl = (string) ($response->body['publicUrl'] ?? '');

        if ($uploadUrl === '' || $publicUrl === '') {
            throw SocialException::platform('The aggregator answered without an upload URL.', 'AGGREGATOR_REQUEST_FAILED');
        }

        // The presigned URL points at the storage bucket, not at the API,
        // so the bytes go there without the key.
        $this->client->upload($uploadUrl, (string) $media->bytes, $media->mimeType);

        return $publicUrl;
    }

    /**
     * The answer, mapped onto the targets.
     *
     * `platformResults[]` carries `{platform, status, error}` and **no
     * account id**, so two accounts of one network cannot be told apart
     * by it. The response's own `post.platforms[]` mirrors the request
     * entries and may carry an `accountId` — that is read first, and the
     * order of the entries is the fallback.
     *
     * @param  list<SocialPostTarget> $targets
     * @return array<int, AggregatorOutcome>
     */
    private function outcomes(ZernioResponse $response, array $targets): array
    {
        $post = is_array($response->body['post'] ?? null) ? $response->body['post'] : [];
        $entries = is_array($post['platforms'] ?? null) ? array_values($post['platforms']) : [];
        $results = is_array($response->body['platformResults'] ?? null)
            ? array_values($response->body['platformResults'])
            : [];

        $postId = is_string($post['_id'] ?? null) ? $post['_id'] : null;

        // A server error and a rate limit are not refusals: the
        // aggregator did not say no, it did not say. The targets stay in
        // `publishing` with their key, and the replay asks again.
        if ($response->status >= 500 || $response->status === 429) {
            return $this->pendingFor($targets, $response->errorMessage());
        }

        // A refusal is the aggregator's own answer, and it fails the
        // targets it names — including the duplicate-content refusal,
        // which the operator is shown as it is.
        if (!$response->isSuccessful()) {
            $error = $response->errorMessage() ?? 'The aggregator refused the post.';

            $outcomes = [];

            foreach ($targets as $target) {
                $outcomes[$target->id] = AggregatorOutcome::failed($error);
            }

            return $outcomes;
        }

        $outcomes = [];

        foreach ($targets as $index => $target) {
            $entry = $this->entryFor($entries, $target, $index, count($targets));
            $result = $this->resultFor($results, $target);

            $status = (string) ($entry['status'] ?? $result['status'] ?? '');
            $error = $entry['errorMessage'] ?? $result['error'] ?? null;

            if ($status === 'published') {
                $outcomes[$target->id] = AggregatorOutcome::published(
                    $postId,
                    is_string($entry['platformPostUrl'] ?? null) ? $entry['platformPostUrl'] : null,
                );

                continue;
            }

            if ($status === 'failed') {
                $outcomes[$target->id] = AggregatorOutcome::failed(
                    is_string($error) && $error !== '' ? $error : 'The aggregator refused the post.',
                );

                continue;
            }

            // pending, processing, uploading: the aggregator is still
            // working on it and retries by itself.
            $outcomes[$target->id] = AggregatorOutcome::pending(
                is_string($error) && $error !== '' ? $error : null,
            );
        }

        return $outcomes;
    }

    /**
     * @param  list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function entryFor(array $entries, SocialPostTarget $target, int $index, int $targetCount): array
    {
        $accountId = (string) ($target->account->external_id ?? '');

        foreach ($entries as $entry) {
            if ($accountId !== '' && (string) ($entry['accountId'] ?? '') === $accountId) {
                return $entry;
            }
        }

        // The entries mirror the request entries, so the order answers
        // when no account id does.
        if (count($entries) === $targetCount) {
            $entry = $entries[$index] ?? null;

            if ($entry !== null) {
                return $entry;
            }
        }

        foreach ($entries as $entry) {
            if ((string) ($entry['platform'] ?? '') === (string) $target->platform) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function resultFor(array $results, SocialPostTarget $target): array
    {
        foreach ($results as $result) {
            if ((string) ($result['platform'] ?? '') === (string) $target->platform) {
                return $result;
            }
        }

        return [];
    }

    /**
     * The outcome of a call whose answer says nothing.
     *
     * @param  list<SocialPostTarget> $targets
     * @return array<int, AggregatorOutcome>
     */
    private function pendingFor(array $targets, ?string $error): array
    {
        $outcomes = [];

        foreach ($targets as $target) {
            $outcomes[$target->id] = AggregatorOutcome::pending($error);
        }

        return $outcomes;
    }

    /**
     * The outcomes, written down.
     *
     * The target's `external_id` is the aggregator's post id — that is
     * what the analytics lookup needs — and its `external_url` is that
     * network's own post URL, so the operator can click it.
     *
     * @param  list<SocialPostTarget>          $targets
     * @param  array<int, AggregatorOutcome>   $outcomes
     * @return bool whether anything changed
     */
    public function write(array $targets, array $outcomes): bool
    {
        $changed = false;

        foreach ($outcomes as $targetId => $outcome) {
            $target = $this->targetOf($targets, (int) $targetId);

            if ($target === null) {
                continue;
            }

            if ($outcome->success) {
                $target->status = SocialPostTarget::STATUS_PUBLISHED;
                $target->external_id = $outcome->postId;
                $target->external_url = $outcome->postUrl;
                $target->error = null;
                $target->published_at = Carbon::now();
                $target->save();
                $changed = true;

                continue;
            }

            if ($outcome->pending) {
                // Still working: the target keeps its status and its
                // key, and the next tick asks again.
                continue;
            }

            $target->status = SocialPostTarget::STATUS_FAILED;
            $target->error = $outcome->error ?? 'The aggregator refused the post.';
            $target->save();
            $changed = true;
        }

        return $changed;
    }

    /**
     * The target of the group, by id.
     *
     * @param  list<SocialPostTarget> $targets
     */
    private function targetOf(array $targets, int $targetId): ?SocialPostTarget
    {
        foreach ($targets as $target) {
            if ((int) $target->id === $targetId) {
                return $target;
            }
        }

        return null;
    }

    /**
     * The connection the group publishes with.
     *
     * @param list<SocialPostTarget> $targets
     */
    private function connectionOf(array $targets): SocialConnection
    {
        foreach ($targets as $target) {
            $connection = $target->account?->connection;

            if ($connection instanceof SocialConnection) {
                return $connection;
            }
        }

        throw SocialException::conflict('This target has no connection, so it has no key.');
    }
}
