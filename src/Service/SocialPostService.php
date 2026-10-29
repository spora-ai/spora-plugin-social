<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostMedia;
use Spora\Plugins\Social\Model\SocialPostTarget;

/**
 * The posts: what they say, where they go and what they carry.
 *
 * The publishing half lives in {@see SocialPublisher}: a post and the act
 * of sending it are two different jobs, and the second one is the one that
 * talks to a platform.
 */
final class SocialPostService
{
    public function __construct(
        private readonly SocialConnectionService $connections,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public function create(int $principalId, array $input): SocialPost
    {
        $accountIds = $this->accountIds($input);
        $accounts = $this->resolveAccounts($principalId, $accountIds);

        $post = new SocialPost();
        $post->principal_id = $principalId;
        $post->title = $this->stringOrNull($input['title'] ?? null);
        $post->body = $this->stringOrNull($input['body'] ?? null);
        $post->platform_bodies = $this->platformBodies($input);
        $post->source = $this->source($input);
        $timezone = $this->timezone($input);
        $post->timezone = $timezone;
        $post->scheduled_for = $this->scheduledFor($input, $timezone);
        $post->status = $post->scheduled_for !== null
            ? SocialPost::STATUS_SCHEDULED
            : SocialPost::STATUS_DRAFT;
        $post->save();

        foreach ($accounts as $account) {
            $target = new SocialPostTarget();
            $target->post_id = $post->id;
            $target->account_id = $account->id;
            $target->platform = (string) $account->platform;
            $target->status = SocialPostTarget::STATUS_PENDING;
            $target->save();
        }

        $this->writeMedia($post, $input);

        return $post->fresh() ?? $post;
    }

    /**
     * @param  array<string, mixed> $filters
     * @return array{items: list<SocialPost>, total: int}
     */
    public function listForPrincipal(int $principalId, array $filters = []): array
    {
        $query = SocialPost::query()->forPrincipal($principalId);

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $accountId = $filters['account_id'] ?? null;
        if (is_numeric($accountId)) {
            $query->whereIn('id', SocialPostTarget::query()
                ->select('post_id')
                ->where('account_id', (int) $accountId));
        }

        $platform = $filters['platform'] ?? null;
        if (is_string($platform) && $platform !== '') {
            $query->whereIn('id', SocialPostTarget::query()
                ->select('post_id')
                ->where('platform', $platform));
        }

        $from = $this->dayBoundary($filters['from'] ?? null, false);
        $to = $this->dayBoundary($filters['to'] ?? null, true);

        if ($from !== null || $to !== null) {
            // The window answers "what is going out in these days": a post
            // that is due in it, and a post that went out in it.
            $query->where(function ($builder) use ($from, $to): void {
                $builder->where(function ($inner) use ($from, $to): void {
                    if ($from !== null) {
                        $inner->where('scheduled_for', '>=', $from);
                    }
                    if ($to !== null) {
                        $inner->where('scheduled_for', '<=', $to);
                    }
                })->orWhere(function ($inner) use ($from, $to): void {
                    if ($from !== null) {
                        $inner->where('published_at', '>=', $from);
                    }
                    if ($to !== null) {
                        $inner->where('published_at', '<=', $to);
                    }
                });
            });
        }

        $total = (clone $query)->count();

        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $items = $query
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->all();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The posts of a month, and how many there are in all.
     *
     * The count is what makes the cap visible: the list itself stops at a
     * hundred, and a caller that sees a hundred has to be able to tell
     * "that is all of them" from "there are more".
     *
     * @return array{items: list<SocialPost>, total: int}
     */
    public function postsOfMonth(int $principalId, string $month): array
    {
        $year = (int) substr($month, 0, 4);
        $monthNumber = (int) substr($month, 5, 2);
        $lastDay = (int) date('t', mktime(0, 0, 0, $monthNumber, 1, $year));

        $result = $this->listForPrincipal($principalId, [
            'limit' => 100,
            'from'  => sprintf('%04d-%02d-01', $year, $monthNumber),
            'to'    => sprintf('%04d-%02d-%02d', $year, $monthNumber, $lastDay),
        ]);

        return ['items' => $result['items'], 'total' => $result['total']];
    }

    public function find(int $postId, int $principalId): SocialPost
    {
        $post = SocialPost::query()
            ->forPrincipal($principalId)
            ->where('id', $postId)
            ->first();

        if ($post === null) {
            throw SocialException::notFound('Post ' . $postId . ' was not found for this principal.');
        }

        return $post;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $postId, int $principalId, array $input): SocialPost
    {
        $post = $this->find($postId, $principalId);

        if (array_key_exists('title', $input)) {
            $post->title = $this->stringOrNull($input['title']);
        }
        if (array_key_exists('body', $input)) {
            $post->body = $this->stringOrNull($input['body']);
        }
        if (array_key_exists('platform_bodies', $input)) {
            $post->platform_bodies = $this->platformBodies($input);
        }

        // The zone first: a wall-clock time is read in the post's zone, so
        // the zone has to be settled before the time is parsed.
        $timezone = $this->timezone($input);

        if (array_key_exists('scheduled_for', $input)) {
            $post->scheduled_for = $this->scheduledFor($input, $timezone);
        }
        if (array_key_exists('timezone', $input)) {
            $post->timezone = $timezone;
        }

        $post->save();

        if (array_key_exists('account_ids', $input)) {
            $this->replaceTargets($post, $this->resolveAccounts($principalId, $this->accountIds($input)));
        }

        if (array_key_exists('media', $input)) {
            $this->replaceMedia($post, $input);
        }

        return $post->fresh() ?? $post;
    }

    public function delete(int $postId, int $principalId): void
    {
        $this->find($postId, $principalId)->delete();
    }

    /**
     * Put an asset on a post.
     *
     * One attachment per post: the publish sends one file, so a second
     * would be a file the operator believes is going out and is not. The
     * composer shows the limit in the preview, after the file is picked,
     * and this refuses the second attachment.
     */
    public function attachMedia(int $postId, int $principalId, string $assetId, int $position = 0): SocialPost
    {
        $post = $this->find($postId, $principalId);

        $existing = $post->media()->where('media_asset_id', $assetId)->first();

        if ($existing !== null) {
            return $post->fresh() ?? $post;
        }

        if ($post->media()->count() >= PlatformFit::MEDIA_PER_POST) {
            throw SocialException::validation(
                'A post carries one attachment. ' . PlatformFit::REMEDY_ONE_ATTACHMENT . '.',
            );
        }

        $attachment = new SocialPostMedia();
        $attachment->post_id = $post->id;
        $attachment->media_asset_id = $assetId;
        $attachment->position = $position;
        $attachment->save();

        return $post->fresh() ?? $post;
    }

    /**
     * Take an asset off a post.
     */
    public function detachMedia(int $postId, int $principalId, string $assetId): SocialPost
    {
        $post = $this->find($postId, $principalId);
        $post->media()->where('media_asset_id', $assetId)->delete();

        return $post->fresh() ?? $post;
    }

    /**
     * @param  array<string, mixed> $input
     * @return list<int>
     */
    private function accountIds(array $input): array
    {
        $raw = $input['account_ids'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int> $accountIds
     * @return list<SocialAccount>
     */
    private function resolveAccounts(int $principalId, array $accountIds): array
    {
        if ($accountIds === []) {
            throw SocialException::validation('A post needs at least one account to publish to.');
        }

        $accounts = [];
        foreach ($accountIds as $accountId) {
            $accounts[] = $this->connections->findAccount($accountId, $principalId);
        }

        return $accounts;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function writeMedia(SocialPost $post, array $input): void
    {
        $media = $input['media'] ?? [];
        if (!is_array($media)) {
            return;
        }

        $position = 0;
        foreach ($media as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $assetId = $entry['media_asset_id'] ?? null;
            if (!is_string($assetId) || $assetId === '') {
                continue;
            }

            $attachment = new SocialPostMedia();
            $attachment->post_id = $post->id;
            $attachment->media_asset_id = $assetId;
            $attachment->position = is_numeric($entry['position'] ?? null) ? (int) $entry['position'] : $position;
            $attachment->save();

            $position++;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function replaceMedia(SocialPost $post, array $input): void
    {
        $post->media()->delete();
        $this->writeMedia($post, $input);
    }

    /**
     * @param list<SocialAccount> $accounts
     */
    private function replaceTargets(SocialPost $post, array $accounts): void
    {
        $post->targets()->delete();

        foreach ($accounts as $account) {
            $target = new SocialPostTarget();
            $target->post_id = $post->id;
            $target->account_id = $account->id;
            $target->platform = (string) $account->platform;
            $target->status = SocialPostTarget::STATUS_PENDING;
            $target->save();
        }
    }

    /**
     * A day as the start or the end of it.
     *
     * `YYYY-MM-DD` alone would cut the last day of a month short at
     * midnight, so the upper bound is the end of the day.
     */
    private function dayBoundary(mixed $raw, bool $endOfDay): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        // A window names no zone, so it is a UTC window: the columns hold
        // UTC and a day boundary has to be the same boundary everywhere.
        return DateTimeParser::toUtc($raw, 'UTC')?->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed> $input
     * @return array<string, string>|null
     */
    private function platformBodies(array $input): ?array
    {
        $raw = $input['platform_bodies'] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $bodies = [];
        foreach ($raw as $platform => $body) {
            if (is_string($platform) && is_string($body)) {
                $bodies[$platform] = $body;
            }
        }

        return $bodies !== [] ? $bodies : null;
    }

    private function source(array $input): string
    {
        $source = $input['source'] ?? SocialPost::SOURCE_MANUAL;
        if (!is_string($source) || !in_array($source, [
            SocialPost::SOURCE_MANUAL,
            SocialPost::SOURCE_AI,
        ], true)) {
            return SocialPost::SOURCE_MANUAL;
        }

        return $source;
    }

    /**
     * The zone the post's wall-clock times are written in.
     *
     * A name PHP does not know falls back to UTC rather than failing the
     * post: the zone is a convenience, and the operator's copy matters
     * more than the zone.
     */
    private function timezone(array $input): string
    {
        $timezone = $input['timezone'] ?? 'UTC';

        if (!is_string($timezone) || $timezone === '') {
            return 'UTC';
        }

        return DateTimeParser::isKnownZone($timezone) ? $timezone : 'UTC';
    }

    /**
     * The moment the post goes out.
     *
     * A value with its own offset is absolute; a value without one is a
     * wall-clock time in the post's own zone.
     */
    private function scheduledFor(array $input, string $timezone): ?Carbon
    {
        $raw = $input['scheduled_for'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $parsed = DateTimeParser::toUtc(trim($raw), $timezone);

        if ($parsed === null) {
            throw SocialException::validation('scheduled_for is not a valid date.');
        }

        return $parsed;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
