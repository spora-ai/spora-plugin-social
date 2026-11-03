<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tools;

use Spora\Plugins\Social\Service\MetricSnapshot;

/**
 * What the social tools answer about the numbers, written out for the model.
 *
 * A counter the platform did not report stays out of the answer rather
 * than becoming a zero that reads as "nobody saw it".
 *
 * Kept out of {@see SocialToolPresenter}: the record and the numbers are
 * two questions, and one class that answers both grows past the
 * twenty-method ceiling a class may have.
 */
final class SocialAnalyticsPresenter
{
    /**
     * What a line says when nothing was counted.
     */
    private const NO_NUMBERS = 'no numbers reported';

    /**
     * One post's numbers, per account it went to.
     *
     * @param array<string, mixed> $analytics
     */
    public static function postMetrics(int $postId, array $analytics): string
    {
        $lines = ['The numbers of post #' . $postId . ' — ' . (string) ($analytics['status'] ?? 'unknown') . ':'];

        foreach ((array) ($analytics['targets'] ?? []) as $target) {
            $lines[] = '- ' . self::measuredTarget($target);
        }

        $lines = array_merge($lines, self::notes((array) ($analytics['notes'] ?? [])));

        return implode("\n", $lines);
    }

    /**
     * The totals of a period.
     *
     * @param array<string, int|null> $totals
     * @param list<string>            $notes
     */
    public static function totals(int $days, array $totals, array $notes): string
    {
        $counters = self::counters($totals);

        $lines = [
            'The totals of the last ' . $days . ' days. The combined figure is a rough one — the platforms count reach differently.',
            $counters !== '' ? $counters : 'No numbers were reported in that period.',
        ];

        return implode("\n", array_merge($lines, self::notes($notes)));
    }

    /**
     * The posts that reached the most people.
     *
     * @param list<array<string, mixed>> $posts
     */
    public static function topPosts(int $days, array $posts): string
    {
        if ($posts === []) {
            return 'No post of the last ' . $days . ' days reached anybody.';
        }

        $lines = ['The top posts of the last ' . $days . ' days:'];

        foreach ($posts as $post) {
            $counters = self::counters($post);

            $lines[] = sprintf(
                '- #%d (%s) — %s%s',
                (int) ($post['post_id'] ?? 0),
                (string) ($post['platform'] ?? ''),
                $counters !== '' ? $counters : self::NO_NUMBERS,
                self::titled($post['title'] ?? null),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * One row per network.
     *
     * @param list<array<string, mixed>> $byNetwork
     * @param list<string>               $notes
     */
    public static function networks(int $days, array $byNetwork, array $notes): string
    {
        $lines = ['One row per network for the last ' . $days . ' days:'];

        foreach ($byNetwork as $network) {
            $counters = self::counters((array) $network);

            $lines[] = sprintf(
                '- %s — %s — %s post(s)',
                (string) ($network['platform'] ?? ''),
                $counters !== '' ? $counters : self::NO_NUMBERS,
                (string) ($network['posts'] ?? 0),
            );
        }

        return implode("\n", array_merge($lines, self::notes($notes)));
    }

    /**
     * The counters that were reported.
     *
     * Public because a publish outcome quotes the same counters: the
     * record presenter writes the numbers of a target that went out.
     *
     * A counter the platform does not report stays empty rather than
     * becoming a zero that reads as "nobody saw it", so it is left out
     * here as well.
     *
     * @param array<string, mixed> $metrics
     */
    public static function counters(array $metrics): string
    {
        $parts = [];

        foreach (MetricSnapshot::COUNTERS as $counter) {
            $value = $metrics[$counter] ?? null;

            if ($value === null) {
                continue;
            }

            $parts[] = $counter . ' ' . $value;
        }

        return implode(', ', $parts);
    }

    /**
     * @param list<string> $notes
     * @return list<string>
     */
    private static function notes(array $notes): array
    {
        $lines = [];

        foreach ($notes as $note) {
            if ($note !== '') {
                $lines[] = 'Note: ' . $note;
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function measuredTarget(array $target): string
    {
        $counters = self::counters((array) ($target['metrics'] ?? []));

        return sprintf(
            '%s (account %d): %s',
            (string) ($target['platform'] ?? ''),
            (int) ($target['account_id'] ?? 0),
            $counters !== '' ? $counters : self::NO_NUMBERS,
        );
    }

    private static function titled(mixed $title): string
    {
        $title = trim((string) $title);

        return $title !== '' ? ' — "' . $title . '"' : '';
    }
}
