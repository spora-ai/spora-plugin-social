<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostMetric;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\OAuth\LinkedInProvider;
use Spora\Plugins\Social\Publish\PlatformHttpClient;
use Spora\Plugins\Social\Publish\PlatformHttpResponse;

/**
 * Reads each platform's own insights API and normalises the answer.
 *
 * The three APIs do not agree on what reach means, and Instagram's
 * impressions are not Facebook's impressions. So the plugin stores a common
 * set of counters and keeps each platform's own numbers beside them, and
 * it does **not** produce one reach number across the networks: the rows
 * stay apart and the combined figure is labelled as a rough one.
 *
 * Every reading is a dated snapshot. Impressions keep climbing for days
 * after a post goes out and the platforms restate them, so nothing here is
 * final.
 */
final class SocialMetricsService
{
    private const LINKEDIN_STATS_URL = 'https://api.linkedin.com/rest/organizationalEntityShareStatistics';
    private const LINKEDIN_SOCIAL_ACTIONS_URL = 'https://api.linkedin.com/rest/socialActions/';

    public function __construct(
        private readonly PlatformHttpClient $http,
        private readonly SocialConnectionService $connections,
        private readonly ApiVersions $versions,
    ) {}

    /**
     * Read one target's numbers and store the day's snapshot.
     */
    public function collect(SocialPostTarget $target): ?SocialPostMetric
    {
        $post = $target->post;
        $account = $target->account;

        if ($post === null || $account === null) {
            return null;
        }

        $platform = SocialPlatform::tryFromName((string) $target->platform);
        if ($platform === null || $target->external_id === null || $target->external_id === '') {
            return null;
        }

        $token = $this->tokenFor($account);

        $snapshot = match ($platform) {
            SocialPlatform::LinkedIn => $this->readLinkedIn($account, (string) $target->external_id, $token),
            SocialPlatform::Facebook => $this->readFacebook((string) $target->external_id, $token),
            SocialPlatform::Instagram => $this->readInstagram((string) $target->external_id, $token),
        };

        $date = DateTimeParser::nowUtc()->format('Y-m-d');

        $row = SocialPostMetric::query()
            ->where('target_id', $target->id)
            ->where('metric_date', $date)
            ->first();

        if ($row === null) {
            $row = new SocialPostMetric();
            $row->post_id = $target->post_id;
            $row->target_id = $target->id;
            $row->metric_date = $date;
        }

        $row->reach = $snapshot->reach;
        $row->impressions = $snapshot->impressions;
        $row->likes = $snapshot->likes;
        $row->comments = $snapshot->comments;
        $row->shares = $snapshot->shares;
        $row->saves = $snapshot->saves;
        $row->clicks = $snapshot->clicks;
        $row->platform_metrics = $snapshot->platformMetrics;
        $row->measured_at = Carbon::now();
        $row->save();

        return $row;
    }

    /**
     * The summary the analytics view renders.
     *
     * @return array{totals: array<string, int>, by_network: list<array<string, mixed>>, series: list<array<string, mixed>>, top_posts: list<array<string, mixed>>, notes: list<string>}
     */
    public function summary(int $principalId, int $days = 30): array
    {
        $since = DateTimeParser::nowUtc()->subDays($days)->format('Y-m-d');

        $rows = $this->metricRows($principalId, $since);

        $totals = [];
        $byNetwork = [];
        $series = [];

        foreach ($rows as $row) {
            $platform = (string) $row->platform;

            $network = $byNetwork[$platform] ?? [
                'platform'    => $platform,
                'reach'       => null,
                'impressions' => null,
                'likes'       => null,
                'comments'    => null,
                'shares'      => null,
                'saves'       => null,
                'clicks'      => null,
                'posts'       => 0,
            ];

            foreach (MetricSnapshot::COUNTERS as $counter) {
                $value = $row->{$counter};
                if ($value === null) {
                    continue;
                }

                $network[$counter] = (int) ($network[$counter] ?? 0) + (int) $value;
                $totals[$counter] = (int) ($totals[$counter] ?? 0) + (int) $value;
            }

            $network['posts'] = (int) $network['posts'] + 1;
            $byNetwork[$platform] = $network;

            $date = (string) $row->metric_date;
            $series[$date] ??= ['date' => $date];
            if ($row->reach !== null) {
                $series[$date][$platform] = (int) ($series[$date][$platform] ?? 0) + (int) $row->reach;
            }
        }

        ksort($series);

        return [
            'totals'     => $this->aggregate($totals),
            'by_network' => array_values($byNetwork),
            'series'     => array_values($series),
            'top_posts'  => $this->topPosts($principalId, $since),
            'notes'      => $this->notes($principalId),
        ];
    }

    /**
     * The sum of what was reported.
     *
     * A counter nobody reported stays null: LinkedIn reports no
     * impressions for a member post, and a zero there reads as "nobody saw
     * it" rather than "this platform does not count that". A counter that
     * was read and is zero stays zero.
     *
     * @param  array<string, int> $totals the counters at least one row reported
     * @return array<string, int|null>
     */
    private function aggregate(array $totals): array
    {
        $aggregate = [];

        foreach (MetricSnapshot::COUNTERS as $counter) {
            $aggregate[$counter] = isset($totals[$counter]) ? (int) $totals[$counter] : null;
        }

        return $aggregate;
    }

    /**
     * One post's numbers, per target.
     *
     * @return array<string, mixed>
     */
    public function postAnalytics(int $postId, int $principalId): array
    {
        $post = SocialPost::query()
            ->forPrincipal($principalId)
            ->where('id', $postId)
            ->first();

        if ($post === null) {
            throw SocialException::notFound('Post ' . $postId . ' was not found for this principal.');
        }

        $targets = [];

        foreach ($post->targets()->get() as $target) {
            $metrics = SocialPostMetric::query()
                ->where('target_id', $target->id)
                ->orderByDesc('metric_date')
                ->first();

            $targets[] = [
                'target_id'    => (int) $target->id,
                'account_id'   => (int) $target->account_id,
                'platform'     => (string) $target->platform,
                'status'       => (string) $target->status,
                'external_id'  => $target->external_id,
                'metrics'      => $metrics === null ? null : $this->metricsOf($metrics),
                'report_note'  => $this->reportNoteFor($target),
            ];
        }

        return [
            'post_id' => (int) $post->id,
            'title'   => $post->title,
            'status'  => (string) $post->status,
            'targets' => $targets,
            'notes'   => $this->notes($principalId),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function metricsOf(SocialPostMetric $row): array
    {
        return [
            'reach'       => $row->reach,
            'impressions' => $row->impressions,
            'likes'       => $row->likes,
            'comments'    => $row->comments,
            'shares'      => $row->shares,
            'saves'       => $row->saves,
            'clicks'      => $row->clicks,
            'measured_at' => $row->measured_at?->toIso8601String(),
        ];
    }

    /**
     * LinkedIn.
     *
     * A member post has no impressions and no click count: the only
     * numbers available are the reactions on the post, which come from
     * the social-actions endpoint. An organisation post has both — the
     * share statistics carry the impressions and the clicks, and the
     * social actions carry the reactions.
     */
    private function readLinkedIn(SocialAccount $account, string $shareUrn, string $token): MetricSnapshot
    {
        if ($account->kind === SocialAccountKind::Organization->value) {
            return $this->readLinkedInOrganisation($account, $shareUrn, $token);
        }

        return $this->readLinkedInMember($account, $shareUrn, $token);
    }

    /**
     * An organisation post: the reactions and the reach.
     *
     * The reactions come from the social actions — "The socialActions
     * endpoint provides up-to-date counts of likes and comments for a
     * share that matches the LinkedIn feed" — and the impressions and the
     * clicks from the share statistics, which are the only place they
     * exist.
     *
     * The two reads need two permissions, and the plugin asks for each on
     * its own: `r_organization_social_feed` for the social actions of an
     * organisation's shares, `rw_organization_admin` for the share
     * statistics. A read the token may not make is skipped rather than
     * spent, so the reactions fall back to the share statistics' counts —
     * the lifetime ones.
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/share-statistics
     */
    private function readLinkedInOrganisation(SocialAccount $account, string $shareUrn, string $token): MetricSnapshot
    {
        $reactions = $this->socialActions(
            $account,
            $shareUrn,
            $token,
            LinkedInProvider::SCOPE_ORGANIZATION_SOCIAL_FEED,
        );

        $totals = $this->shareStatistics(
            $account,
            $shareUrn,
            $token,
            LinkedInProvider::SCOPE_ORGANIZATION_ADMIN_REPORTING,
        );

        return new MetricSnapshot(
            reach: $this->intOrNull($totals['uniqueImpressionsCount'] ?? null),
            impressions: $this->intOrNull($totals['impressionCount'] ?? null),
            likes: $reactions['likes'] ?? $this->intOrNull($totals['likeCount'] ?? null),
            comments: $reactions['comments'] ?? $this->intOrNull($totals['commentCount'] ?? null),
            shares: $this->intOrNull($totals['shareCount'] ?? null),
            clicks: $this->intOrNull($totals['clickCount'] ?? null),
            platformMetrics: [
                'share_statistics' => $totals,
                'social_actions'   => $reactions['body'],
            ],
        );
    }

    /**
     * The share statistics of an organisation's post.
     *
     * `rw_organization_admin` covers the read — "Manage organizations
     * pages and retrieve reporting data".
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/organizations/share-statistics
     *
     * @return array<string, mixed>
     */
    private function shareStatistics(
        SocialAccount $account,
        string $shareUrn,
        string $token,
        string $scope,
    ): array {
        if (!$this->mayUseScope($account, $scope)) {
            return [];
        }

        $response = $this->http->request(
            'GET',
            self::LINKEDIN_STATS_URL,
            [
                'query'   => [
                    'q'                    => 'organizationalEntity',
                    'organizationalEntity' => $account->external_id,
                    'shares'               => 'List(' . $shareUrn . ')',
                ],
                'headers' => $this->versions->linkedInRestHeaders($token),
            ],
        );

        return $this->firstElementTotals($response);
    }

    /**
     * A member post: the reactions alone.
     *
     * `r_member_social_feed` covers the read — "Retrieve posts, comments,
     * and likes on behalf of an authenticated member".
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
     */
    private function readLinkedInMember(SocialAccount $account, string $shareUrn, string $token): MetricSnapshot
    {
        $reactions = $this->socialActions(
            $account,
            $shareUrn,
            $token,
            LinkedInProvider::SCOPE_MEMBER_SOCIAL_FEED,
        );

        return new MetricSnapshot(
            reach: null,
            impressions: null,
            likes: $reactions['likes'],
            comments: $reactions['comments'],
            platformMetrics: $reactions['body'],
            note: AccountReporting::MEMBER_NOTE,
        );
    }

    /**
     * The reactions of a share, when the token may read them.
     *
     * A token without the scope is refused by the platform, so the read
     * is skipped rather than spent — a request that will always be
     * refused is not worth making every day.
     *
     * @return array{likes: ?int, comments: ?int, body: array<string, mixed>}
     */
    private function socialActions(
        SocialAccount $account,
        string $shareUrn,
        string $token,
        string $scope,
    ): array {
        if (!$this->mayUseScope($account, $scope)) {
            return ['likes' => null, 'comments' => null, 'body' => []];
        }

        $response = $this->http->request(
            'GET',
            self::LINKEDIN_SOCIAL_ACTIONS_URL . rawurlencode($shareUrn),
            ['headers' => $this->versions->linkedInRestHeaders($token)],
        );

        $body = $response->body;

        return [
            'likes'    => $this->intOrNull($body['likesSummary']['totalLikes'] ?? null),
            'comments' => $this->intOrNull($body['commentsSummary']['totalFirstLevelComments'] ?? null),
            'body'     => $body,
        ];
    }

    /**
     * Whether the token may make a read that needs the given scope.
     *
     * A token whose scopes were never recorded cannot be judged, so the
     * read is attempted rather than skipped: a refused read is the
     * platform's answer, a read never made is the plugin's silence. The
     * rule is one rule for both reads, so a scope that is missing and a
     * scope that is unknown are not confused.
     */
    private function mayUseScope(SocialAccount $account, string $scope): bool
    {
        $granted = $this->connections->grantedScopes($this->connectionOf($account));

        return $granted === [] || in_array($scope, $granted, true);
    }

    /**
     * The token the platform expects on a read.
     *
     * The connection's token — the one the publish went out under. Under
     * the Meta Unified Login it is a Business Integration System User
     * Access Token, which covers every asset the client shared, and
     * Facebook's Page post insights take it:
     * `GET /{page-post-id}/insights?metric=...&access_token={access-token}`.
     *
     * @see https://developers.facebook.com/documentation/pages-api/platforminsights/page
     */
    private function tokenFor(SocialAccount $account): string
    {
        return $this->connections->tokenFor($account);
    }

    /**
     * The connection an account belongs to.
     */
    private function connectionOf(SocialAccount $account): SocialConnection
    {
        $connection = $account->connection;

        if ($connection === null) {
            throw SocialException::conflict('This account has no connection, so it has no token.');
        }

        return $connection;
    }

    /**
     * Facebook.
     *
     * The insights endpoint gives impressions and clicks; the reactions
     * live on the post itself and are read separately.
     */
    private function readFacebook(string $postId, string $token): MetricSnapshot
    {
        $insights = $this->http->request('GET', $this->versions->graphUrl(
            ApiVersions::GRAPH_HOST,
            '/' . $postId . '/insights',
        ), [
            'query'   => [
                'metric'       => 'post_impressions,post_impressions_unique,post_clicks',
                'access_token' => $token,
            ],
        ]);

        $values = $this->insightValues($insights);

        $post = $this->http->request('GET', $this->versions->graphUrl(
            ApiVersions::GRAPH_HOST,
            '/' . $postId,
        ), [
            'query'   => [
                'fields'       => 'likes.summary(true).total_count,comments.summary(true).total_count,shares',
                'access_token' => $token,
            ],
        ]);

        $body = $post->body;

        return new MetricSnapshot(
            reach: $this->intOrNull($values['post_impressions_unique'] ?? null),
            impressions: $this->intOrNull($values['post_impressions'] ?? null),
            likes: $this->intOrNull($body['likes']['summary']['total_count'] ?? null),
            comments: $this->intOrNull($body['comments']['summary']['total_count'] ?? null),
            shares: $this->intOrNull($body['shares']['count'] ?? null),
            clicks: $this->intOrNull($values['post_clicks'] ?? null),
            platformMetrics: ['insights' => $values, 'post' => $body],
        );
    }

    /**
     * Instagram.
     */
    private function readInstagram(string $mediaId, string $token): MetricSnapshot
    {
        $response = $this->http->request('GET', $this->versions->graphUrl(
            ApiVersions::GRAPH_HOST,
            '/' . $mediaId . '/insights',
        ), [
            'query'   => [
                'metric'       => 'impressions,reach,likes,comments,saved,shares,total_interactions',
                'access_token' => $token,
            ],
        ]);

        $values = $this->insightValues($response);

        return new MetricSnapshot(
            reach: $this->intOrNull($values['reach'] ?? null),
            impressions: $this->intOrNull($values['impressions'] ?? null),
            likes: $this->intOrNull($values['likes'] ?? null),
            comments: $this->intOrNull($values['comments'] ?? null),
            shares: $this->intOrNull($values['shares'] ?? null),
            saves: $this->intOrNull($values['saved'] ?? null),
            platformMetrics: $values,
        );
    }

    /**
     * Meta's insights answer is a list of named metrics.
     *
     * @return array<string, int>
     */
    private function insightValues(PlatformHttpResponse $response): array
    {
        $data = $response->body['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        $values = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = $entry['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }

            $metricValues = $entry['values'] ?? null;
            if (!is_array($metricValues) || $metricValues === []) {
                continue;
            }

            $first = $metricValues[0] ?? null;
            if (!is_array($first)) {
                continue;
            }

            $value = $first['value'] ?? null;
            if (is_numeric($value)) {
                $values[$name] = (int) $value;
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstElementTotals(PlatformHttpResponse $response): array
    {
        $elements = $response->body['elements'] ?? null;
        if (!is_array($elements) || $elements === []) {
            return [];
        }

        $first = $elements[0] ?? null;
        if (!is_array($first)) {
            return [];
        }

        $totals = $first['totalShareStatistics'] ?? null;

        return is_array($totals) ? $totals : [];
    }

    /**
     * The metric rows of a period.
     *
     * The query builder rather than Eloquent: the rows carry columns from
     * three tables, and a model that does not own them would have to
     * pretend otherwise.
     *
     * @return list<object>
     */
    private function metricRows(int $principalId, string $since): array
    {
        return Capsule::table('social_post_metrics')
            ->join('social_post_targets', 'social_post_targets.id', '=', 'social_post_metrics.target_id')
            ->join('social_posts', 'social_posts.id', '=', 'social_post_metrics.post_id')
            ->where('social_posts.principal_id', $principalId)
            ->where('social_post_metrics.metric_date', '>=', $since)
            ->select([
                'social_post_metrics.metric_date',
                'social_post_metrics.reach',
                'social_post_metrics.impressions',
                'social_post_metrics.likes',
                'social_post_metrics.comments',
                'social_post_metrics.shares',
                'social_post_metrics.saves',
                'social_post_metrics.clicks',
                'social_post_targets.platform',
            ])
            ->get()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topPosts(int $principalId, string $since): array
    {
        $rows = Capsule::table('social_post_metrics')
            ->join('social_post_targets', 'social_post_targets.id', '=', 'social_post_metrics.target_id')
            ->join('social_posts', 'social_posts.id', '=', 'social_post_metrics.post_id')
            ->where('social_posts.principal_id', $principalId)
            ->where('social_post_metrics.metric_date', '>=', $since)
            ->select([
                'social_posts.id as post_id',
                'social_posts.title as title',
                'social_post_targets.platform as platform',
                'social_post_metrics.reach as reach',
                'social_post_metrics.impressions as impressions',
                'social_post_metrics.likes as likes',
                'social_posts.published_at as published_at',
            ])
            ->get();

        $posts = [];
        foreach ($rows as $row) {
            $posts[] = [
                'post_id'      => (int) $row->post_id,
                'title'        => $row->title,
                'platform'     => (string) $row->platform,
                'reach'        => $row->reach === null ? null : (int) $row->reach,
                'impressions'  => $row->impressions === null ? null : (int) $row->impressions,
                'likes'        => $row->likes === null ? null : (int) $row->likes,
                'published_at' => $row->published_at,
            ];
        }

        usort($posts, static function (array $a, array $b): int {
            return ($b['reach'] ?? 0) <=> ($a['reach'] ?? 0);
        });

        return array_slice($posts, 0, 10);
    }

    /**
     * The caveats that belong with the numbers.
     *
     * @return list<string>
     */
    private function notes(int $principalId): array
    {
        $notes = [];

        $memberAccounts = SocialPostTarget::query()
            ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.post_id')
            ->where('social_posts.principal_id', $principalId)
            ->where('social_post_targets.platform', SocialPlatform::LinkedIn->value)
            ->where('social_post_targets.status', SocialPostTarget::STATUS_PUBLISHED)
            ->get();

        foreach ($memberAccounts as $target) {
            $account = $target->account;
            if ($account !== null && $account->kind === SocialAccountKind::Member->value) {
                $notes[] = 'LinkedIn member posts report reactions only — the reach column is empty by design.';
                break;
            }
        }

        $personalInstagram = SocialPostTarget::query()
            ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.post_id')
            ->where('social_posts.principal_id', $principalId)
            ->where('social_post_targets.platform', SocialPlatform::Instagram->value)
            ->get();

        foreach ($personalInstagram as $target) {
            $account = $target->account;
            if ($account !== null && $account->kind === SocialAccountKind::Member->value) {
                $notes[] = 'Instagram personal accounts have no media insights.';
                break;
            }
        }

        $notes[] = 'Every reading is a dated snapshot — the platforms keep revising impressions for days.';

        return $notes;
    }

    private function reportNoteFor(SocialPostTarget $target): ?string
    {
        $account = $target->account;
        if ($account === null) {
            return null;
        }

        if ($target->platform === SocialPlatform::LinkedIn->value && $account->kind === SocialAccountKind::Member->value) {
            return AccountReporting::MEMBER_NOTE;
        }

        if ($target->platform === SocialPlatform::Instagram->value && $account->kind === SocialAccountKind::Member->value) {
            return AccountReporting::PERSONAL_INSTAGRAM_NOTE;
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
