<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialPost;

/**
 * The post's own fields, read out of a request body.
 *
 * The body is what the agent or the frontend sends; the post is what the
 * plugin stores. The mapping between them is here, so the service that
 * stores a post does not also have to read a body.
 */
final class PostInput
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(private readonly array $input) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function title(): ?string
    {
        return $this->stringOrNull($this->input['title'] ?? null);
    }

    public function body(): ?string
    {
        return $this->stringOrNull($this->input['body'] ?? null);
    }

    /**
     * A body per platform.
     *
     * @return array<string, string>|null
     */
    public function platformBodies(): ?array
    {
        $raw = $this->input['platform_bodies'] ?? null;
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

    public function source(): string
    {
        $source = $this->input['source'] ?? SocialPost::SOURCE_MANUAL;
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
    public function timezone(): string
    {
        $timezone = $this->input['timezone'] ?? 'UTC';

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
    public function scheduledFor(string $timezone): ?Carbon
    {
        $raw = $this->input['scheduled_for'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $parsed = DateTimeParser::toUtc(trim($raw), $timezone);

        if ($parsed === null) {
            throw SocialException::validation('scheduled_for is not a valid date.');
        }

        return $parsed;
    }

    /**
     * The accounts the post goes to.
     *
     * @return list<int>
     */
    public function accountIds(): array
    {
        $raw = $this->input['account_ids'] ?? [];
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

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
