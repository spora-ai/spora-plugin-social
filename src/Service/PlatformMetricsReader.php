<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\OAuth\LinkedInProvider;
use Spora\Plugins\Social\Publish\PlatformHttpClient;
use Spora\Plugins\Social\Publish\PlatformHttpResponse;

/**
 * Reads one platform's insights API.
 *
 * The three APIs do not agree on what reach means, and Instagram's
 * impressions are not Facebook's impressions. So the plugin stores a common
 * set of counters and keeps each platform's own numbers beside them, and
 * it does **not** produce one reach number across the networks.
 */
final class PlatformMetricsReader
{
    private const LINKEDIN_STATS_URL = 'https://api.linkedin.com/rest/organizationalEntityShareStatistics';
    private const LINKEDIN_SOCIAL_ACTIONS_URL = 'https://api.linkedin.com/rest/socialActions/';

    public function __construct(
        private readonly PlatformHttpClient $http,
        private readonly SocialConnectionService $connections,
        private readonly ApiVersions $versions,
    ) {}

    /**
     * The numbers one published target has.
     */
    public function read(SocialPlatform $platform, SocialAccount $account, string $externalId): MetricSnapshot
    {
        $token = $this->tokenFor($account);

        return match ($platform) {
            SocialPlatform::LinkedIn => $this->readLinkedIn($account, $externalId, $token),
            SocialPlatform::Facebook => $this->readFacebook($externalId, $token),
            SocialPlatform::Instagram => $this->readInstagram($externalId, $token),
        };
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

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
