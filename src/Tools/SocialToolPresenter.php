<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tools;

use Spora\Plugins\Social\Model\SocialChannel;
use Spora\Plugins\Social\Service\PreparedMedia;

/**
 * What the social tools answer about the social record, written out for
 * the model.
 *
 * `ToolResult::$data` never reaches the model: it is stored in
 * `tool_calls.result_data` for the operator's UI and the audit trail.
 * A read operation that leaves its rows there answers with a count and
 * nothing else — and a model that cannot see the ids has to guess them,
 * which is how an agent ends up probing for an account that does not
 * exist.
 *
 * The rows therefore go into the content, one line each, and the
 * structured rows stay in the data for the UI.
 *
 * The lines are what the model acts on, so they carry the handles:
 * the asset id, the account id, the post id. A line without a handle
 * is a count with extra words.
 *
 * The numbers live in {@see SocialAnalyticsPresenter}: the record and
 * the numbers are two questions, and one class that answers both grows
 * past the twenty methods a class may have.
 */
final class SocialToolPresenter
{
    /**
     * How much of a post's body the list carries.
     *
     * Enough to recognise the post and to see that it is the one the
     * operator is about to write again — not enough to fill the context
     * window with copy that is already in the archive.
     */
    private const EXCERPT_LENGTH = 120;

    /**
     * The media library.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function assets(array $rows, int $total): string
    {
        if ($rows === []) {
            return 'No media asset is in this principal\'s library.';
        }

        $lines = [self::page(count($rows), $total, 'media asset(s)') . ':'];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '- %s %s — %s, %s bytes',
                (string) ($row['id'] ?? ''),
                (string) ($row['filename'] ?? '(unnamed)'),
                (string) ($row['mime_type'] ?? 'unknown type'),
                (string) ($row['size_bytes'] ?? 0),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The connections, with the accounts each one covers.
     *
     * The account ids are the point of the list: they are what
     * `social_post` takes, and an agent that cannot see them has
     * nothing to post to.
     *
     * @param list<array<string, mixed>> $connections
     */
    public static function connections(array $connections): string
    {
        if ($connections === []) {
            return 'No social account is connected for this principal.';
        }

        $lines = [count($connections) . ' social connection(s):'];

        foreach ($connections as $connection) {
            $lines[] = '- ' . self::connectionLine($connection);

            foreach ((array) ($connection['accounts'] ?? []) as $account) {
                $lines[] = '  - ' . self::accountLine($account);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The posts of a principal.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function posts(array $items, int $total): string
    {
        if ($items === []) {
            return 'No post is written for this principal.';
        }

        $lines = [self::page(count($items), $total, 'post(s)') . ':'];

        foreach ($items as $post) {
            $lines[] = '- ' . self::postLine($post);
        }

        return implode("\n", $lines);
    }

    /**
     * The posts of a month, with the day each one is due or went out.
     *
     * @param list<array<string, mixed>> $posts
     */
    public static function month(string $month, array $posts, int $total): string
    {
        if ($posts === []) {
            return 'No post is due or went out in ' . $month . '.';
        }

        $lines = [self::page(count($posts), $total, 'post(s)') . ' in ' . $month . ':'];

        foreach ($posts as $post) {
            $lines[] = '- ' . self::postLine($post);
        }

        return implode("\n", $lines);
    }

    /**
     * What a post carries: its copy, its attachments and its outcomes.
     *
     * @param array<string, mixed> $post
     */
    public static function postDetails(array $post): string
    {
        $lines = [];

        $targets = (array) ($post['targets'] ?? []);

        // A draft may be written before any account is connected. It has
        // no platform to go to, and the agent has to know that — or it
        // tells the operator a post is ready that cannot go out.
        if ($targets === []) {
            $lines[] = '- no account: the post has no platform to go to, so it cannot be published yet';
        }

        $body = trim((string) ($post['body'] ?? ''));
        if ($body !== '') {
            $lines[] = '- body: ' . $body;
        }

        foreach ((array) ($post['platform_bodies'] ?? []) as $platform => $platformBody) {
            $lines[] = '- ' . $platform . ' body: ' . $platformBody;
        }

        foreach ((array) ($post['hashtags'] ?? []) as $hashtag) {
            $lines[] = '- hashtag: ' . $hashtag;
        }

        foreach ((array) ($post['media'] ?? []) as $attachment) {
            $lines[] = '- attachment: ' . (string) ($attachment['media_asset_id'] ?? '')
                . ' (' . (string) ($attachment['mime_type'] ?? '') . ')';
        }

        foreach ((array) ($post['targets'] ?? []) as $target) {
            $lines[] = '- ' . self::targetOutcome($target);
        }

        return $lines === [] ? 'The post carries nothing yet.' : implode("\n", $lines);
    }

    /**
     * The fit of an asset, per channel.
     *
     * The verdict is the one the publish will reach: the media is
     * judged as the platform receives it, so a PNG that the backend
     * converts for Instagram fits there.
     *
     * @param list<array<string, mixed>> $perChannel
     */
    public static function fit(bool $fits, array $perChannel): string
    {
        $lines = [$fits
            ? 'The asset fits every channel asked about.'
            : 'The asset does not fit every channel asked about.'];

        foreach ($perChannel as $entry) {
            $lines[] = '- ' . self::fitLine($entry);
        }

        return implode("\n", $lines);
    }

    /**
     * The prepared media, with the handles the agent needs.
     *
     * The derivative id and the public URL are in the answer, because
     * an agent that cannot see them cannot tell what Instagram will
     * fetch — and the original asset id is repeated, because that is
     * the one the post carries.
     */
    public static function prepared(string $sourceId, PreparedMedia $prepared): string
    {
        if ($prepared->derivativeFormat !== null) {
            $lines = [sprintf(
                'Prepared asset %s for Instagram as %s (%s) — the derivative is asset %s.',
                $sourceId,
                $prepared->mimeType,
                $prepared->derivativeFormat,
                $prepared->mediaAssetId,
            )];
        } else {
            $lines = [sprintf(
                'Prepared asset %s for Instagram as %s — no conversion was needed.',
                $sourceId,
                $prepared->mimeType,
            )];
        }

        if ($prepared->publicUrl !== null) {
            $lines[] = 'The public URL Instagram will fetch: ' . $prepared->publicUrl;
        }

        $lines[] = 'Attach the original asset id ' . $sourceId . ' to the post; the backend publishes the converted image.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $connection
     */
    private static function connectionLine(array $connection): string
    {
        $parts = [(string) ($connection['provider'] ?? ''), (string) ($connection['status'] ?? '')];

        $expires = $connection['expires_at'] ?? null;
        if (is_string($expires) && $expires !== '') {
            $parts[] = 'expires ' . $expires;
        }

        if (($connection['needs_reconnect'] ?? false) === true) {
            $parts[] = 'needs a reconnect';
        }

        return sprintf(
            'connection %d (%s)',
            (int) ($connection['id'] ?? 0),
            implode(', ', $parts),
        );
    }

    /**
     * @param array<string, mixed> $account
     */
    private static function accountLine(array $account): string
    {
        $name = trim((string) ($account['display_name'] ?? ''));

        $label = $name;

        if ($label === '') {
            $username = trim((string) ($account['username'] ?? ''));
            $label = $username !== '' ? '@' . $username : '(unnamed)';
        }

        return sprintf(
            'account %d (%s) — %s',
            (int) ($account['account_id'] ?? $account['id'] ?? 0),
            // The channel and not the network: the same network on two
            // routes is two accounts, and two lines that both say
            // "linkedin" say nothing about which is which.
            SocialChannel::keyOf(
                (string) ($account['via'] ?? ''),
                (string) ($account['platform'] ?? ''),
            ),
            $label,
        );
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function postLine(array $post): string
    {
        $parts = [];

        $status = (string) ($post['status'] ?? '');
        if ($status !== '') {
            $parts[] = $status;
        }

        $when = $post['scheduled_for'] ?? $post['published_at'] ?? null;
        if (is_string($when) && $when !== '') {
            $parts[] = $when;
        }

        $title = trim((string) ($post['title'] ?? ''));
        if ($title !== '') {
            $parts[] = '"' . $title . '"';
        }

        $body = trim((string) ($post['body'] ?? ''));
        if ($body !== '') {
            $parts[] = self::excerpt($body);
        }

        return '#' . (int) ($post['id'] ?? $post['post_id'] ?? 0) . ' — ' . implode(' — ', $parts);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function fitLine(array $entry): string
    {
        $channel = (string) ($entry['channel'] ?? '');
        $mime = (string) ($entry['mime_type'] ?? '');

        if (($entry['fits'] ?? false) === false) {
            $line = $channel . ': does not fit — ' . implode(' ', (array) ($entry['reasons'] ?? []));

            $remedy = $entry['remedy'] ?? null;
            if (is_string($remedy) && $remedy !== '') {
                $line .= '. Remedy: ' . $remedy . '.';
            }

            return $line;
        }

        $line = sprintf(
            '%s: fits (%s, %s bytes)',
            $channel,
            $mime,
            (string) ($entry['size_bytes'] ?? 0),
        );

        $convertedFrom = $entry['converted_from'] ?? null;
        if (is_string($convertedFrom) && $convertedFrom !== '') {
            $line .= ' — ' . $convertedFrom . ' is converted to ' . $mime . ' for ' . $channel;
        }

        return $line;
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function targetOutcome(array $target): string
    {
        $parts = [
            // The channel and not the network: the same network on two
            // routes is two accounts, and two lines that both say
            // "linkedin" tell the agent nothing about which went out.
            SocialChannel::keyOf(
                (string) ($target['via'] ?? ''),
                (string) ($target['platform'] ?? ''),
            ),
            'account ' . (int) ($target['account_id'] ?? 0),
            (string) ($target['status'] ?? ''),
        ];

        $error = $target['error'] ?? null;
        if (is_string($error) && $error !== '') {
            $parts[] = $error;
        }

        $url = $target['external_url'] ?? null;
        if (is_string($url) && $url !== '') {
            $parts[] = $url;
        }

        $counters = SocialAnalyticsPresenter::counters((array) ($target['metrics'] ?? []));
        if ($counters !== '') {
            $parts[] = $counters;
        }

        return implode(' — ', $parts);
    }

    /**
     * How many rows there are, and how many there are in all.
     */
    private static function page(int $shown, int $total, string $noun): string
    {
        return $total > $shown
            ? sprintf('%d of %d %s', $shown, $total, $noun)
            : sprintf('%d %s', $shown, $noun);
    }

    private static function excerpt(string $body): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $body));

        if (mb_strlen($flat) <= self::EXCERPT_LENGTH) {
            return $flat;
        }

        return mb_substr($flat, 0, self::EXCERPT_LENGTH - 1) . '…';
    }
}
