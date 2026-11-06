<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Illuminate\Database\Eloquent\Builder;
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
        private readonly SocialPostAccounts $accounts,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public function create(int $principalId, array $input): SocialPost
    {
        $fields = new PostInput($input);

        $timezone = $fields->timezone();
        $scheduledFor = $fields->scheduledFor($timezone);

        // A draft may sit without an account: the accounts name the
        // platforms, and a draft has not been sent anywhere yet. A
        // scheduled post commits the copy to go out, so it has to know
        // where to.
        $accounts = $this->accounts->resolve($principalId, $fields->accountIds(), $scheduledFor !== null);

        $post = new SocialPost();
        $post->principal_id = $principalId;
        $post->title = $fields->title();
        $post->body = $fields->body();
        $post->platform_bodies = $fields->platformBodies();
        $post->hashtags = $fields->hashtags();
        $post->source = $fields->source();
        $post->timezone = $timezone;
        $post->scheduled_for = $scheduledFor;
        $post->status = $scheduledFor !== null
            ? SocialPost::STATUS_SCHEDULED
            : SocialPost::STATUS_DRAFT;

        $media = $this->mediaEntries($input);

        $this->assertHasCopy($post, $media !== []);

        $post->save();

        foreach ($accounts as $account) {
            $target = new SocialPostTarget();
            $target->post_id = $post->id;
            $target->account_id = $account->id;
            $target->platform = (string) $account->platform;
            // The target goes out the way its account does: the route is
            // the account's, and the target only names the network.
            $target->via = (string) $account->via;
            $target->status = SocialPostTarget::STATUS_PENDING;
            $target->save();
        }

        $this->writeMedia($post, $media);

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

        $this->applyWindow($query, $filters);

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
     * The window the list is narrowed by.
     *
     * @param array<string, mixed> $filters
     */
    private function applyWindow(Builder $query, array $filters): void
    {
        $from = $this->dayBoundary($filters['from'] ?? null, false);
        $to = $this->dayBoundary($filters['to'] ?? null, true);

        if ($from === null && $to === null) {
            return;
        }

        // The window answers "what is going out in these days": a post
        // that is due in it, and a post that went out in it.
        $query->where(function (Builder $builder) use ($from, $to): void {
            $builder->where(function (Builder $inner) use ($from, $to): void {
                $this->between($inner, 'scheduled_for', $from, $to);
            })->orWhere(function (Builder $inner) use ($from, $to): void {
                $this->between($inner, 'published_at', $from, $to);
            });
        });
    }

    /**
     * The column inside the window, when the window has a bound for it.
     */
    private function between(Builder $builder, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $builder->where($column, '>=', $from);
        }

        if ($to !== null) {
            $builder->where($column, '<=', $to);
        }
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
        $fields = new PostInput($input);

        if ($fields->has('title')) {
            $post->title = $fields->title();
        }
        if ($fields->has('body')) {
            $post->body = $fields->body();
        }
        if ($fields->has('platform_bodies')) {
            $post->platform_bodies = $fields->platformBodies();
        }
        if ($fields->has('hashtags')) {
            $post->hashtags = $fields->hashtags();
        }

        // The zone first: a wall-clock time is read in the post's zone, so
        // the zone has to be settled before the time is parsed.
        $timezone = $fields->timezone();

        $scheduledFor = $fields->has('scheduled_for') ? $fields->scheduledFor($timezone) : null;

        // The accounts next, and before anything is written: a post that
        // goes out has to know where to, and a refusal must not leave the
        // post half-changed.
        $accounts = null;

        if ($fields->has('account_ids')) {
            $goesOut = $post->status !== SocialPost::STATUS_DRAFT || $scheduledFor !== null;

            $accounts = $this->accounts->resolve($principalId, $fields->accountIds(), $goesOut);
        }

        // A post that goes out needs an account, whether the update names
        // one or the post already carries it.
        if ($accounts === null && $this->accounts->goesOut($post, $scheduledFor) && !$post->targets()->exists()) {
            throw $this->accounts->noAccount($principalId);
        }

        if ($fields->has('scheduled_for')) {
            $post->scheduled_for = $scheduledFor;
            $this->reschedule($post);
        }
        if ($fields->has('timezone')) {
            $post->timezone = $timezone;
        }

        $media = $this->mediaEntries($input);
        $hasMedia = $fields->has('media') ? $media !== [] : $post->media()->exists();

        $this->assertHasCopy($post, $hasMedia);

        $post->save();

        if ($accounts !== null) {
            $this->replaceTargets($post, $accounts);
        }

        if ($fields->has('media')) {
            $this->replaceMedia($post, $media);
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
     * The attachments a request asks for.
     *
     * @param  array<string, mixed> $input
     * @return list<array{media_asset_id: string, position: int}>
     */
    private function mediaEntries(array $input): array
    {
        $media = $input['media'] ?? [];
        if (!is_array($media)) {
            return [];
        }

        $entries = [];
        $position = 0;

        foreach ($media as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $assetId = $entry['media_asset_id'] ?? null;
            if (!is_string($assetId) || $assetId === '') {
                continue;
            }

            $entries[] = [
                'media_asset_id' => $assetId,
                'position'      => is_numeric($entry['position'] ?? null) ? (int) $entry['position'] : $position,
            ];

            $position++;
        }

        return $entries;
    }

    /**
     * @param list<array{media_asset_id: string, position: int}> $media
     */
    private function writeMedia(SocialPost $post, array $media): void
    {
        foreach ($media as $entry) {
            $attachment = new SocialPostMedia();
            $attachment->post_id = $post->id;
            $attachment->media_asset_id = $entry['media_asset_id'];
            $attachment->position = $entry['position'];
            $attachment->save();
        }
    }

    /**
     * @param list<array{media_asset_id: string, position: int}> $media
     */
    private function replaceMedia(SocialPost $post, array $media): void
    {
        $post->media()->delete();
        $this->writeMedia($post, $media);
    }

    /**
     * A post has to say something or carry something.
     *
     * The post is judged as it ends up, not as it was asked for: an edit
     * that empties the body of a post that has media still leaves a post
     * that can go out, and an edit that empties a post that has nothing
     * else leaves a post that cannot.
     */
    private function assertHasCopy(SocialPost $post, bool $hasMedia): void
    {
        if (trim((string) $post->body) !== '') {
            return;
        }

        foreach ((array) $post->platform_bodies as $body) {
            if (trim($body) !== '') {
                return;
            }
        }

        if ($hasMedia) {
            return;
        }

        throw SocialException::validation('A post needs a message or media.');
    }

    /**
     * The status a scheduled date implies.
     *
     * Only a post that has not gone out yet follows the date: one that is
     * publishing, published, partial, failed or cancelled has been through
     * the networks, and an edit must not undo that.
     */
    private function reschedule(SocialPost $post): void
    {
        if (!in_array($post->status, [SocialPost::STATUS_DRAFT, SocialPost::STATUS_SCHEDULED], true)) {
            return;
        }

        $post->status = $post->scheduled_for !== null
            ? SocialPost::STATUS_SCHEDULED
            : SocialPost::STATUS_DRAFT;
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
            $target->via = (string) $account->via;
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
}
