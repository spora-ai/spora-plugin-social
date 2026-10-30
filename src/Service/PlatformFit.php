<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Plugins\Social\Model\SocialPlatform;

/**
 * What each platform accepts: the media rules and the character limit.
 *
 * One table, because the rules are read in three places — the composer
 * badge on an attachment, the publish-time guard, and the prose the agent
 * reads — and three copies of a size cap would drift.
 *
 * The verdict names the remedy, not just the failure: an operator can act
 * on "Convert to JPEG" and not on a platform's error text.
 *
 * The table carries two facts that are not platform rules but plugin
 * rules — how many attachments a post may carry, and which networks fetch
 * the media themselves. They live here for the same reason the limits
 * do: the frontend used to keep its own copy of both, and a copy is
 * where a rule goes stale.
 *
 * Both are enforced, not merely stated: the attach and the publish refuse
 * a second attachment, and an Instagram publish is refused without a
 * publicly reachable URL — the media pipeline mints one, because Instagram
 * fetches the file itself.
 */
final class PlatformFit
{
    public const REMEDY_CONVERT_JPEG = 'Convert to JPEG';
    public const REMEDY_COMPRESS = 'Compress';
    public const REMEDY_ATTACH_MEDIA = 'Attach a JPEG image or an MP4 video';
    public const REMEDY_CONVERT_VIDEO = 'Convert the file to MP4';
    public const REMEDY_SHORTEN = 'Shorten the post';
    public const REMEDY_ONE_ATTACHMENT = 'Keep one attachment per post';

    /**
     * What the composer shows when a body is over the limit.
     *
     * The frontend's own wording, so the operator reads one thing and not
     * two: the pill says "Over the limit" and the counter reads
     * "5000 / 2200".
     */
    public const OVER_THE_LIMIT = 'Over the limit';

    private const MEGABYTE = 1048576;

    private const MIME_JPEG = 'image/jpeg';
    private const MIME_PNG = 'image/png';
    private const MIME_MP4 = 'video/mp4';

    /**
     * How many attachments a post may carry.
     *
     * One, for every platform: the publish sends the first attachment and
     * nothing else, so a second is a file the operator believes is going
     * out and is not.
     */
    public const MEDIA_PER_POST = 1;

    /**
     * @return array<string, array{
     *     label: string,
     *     media_required: bool,
     *     media_count: int,
     *     fetches_media: bool,
     *     mime_types: list<string>,
     *     max_bytes: int,
     *     max_characters: int,
     *     media: string
     * }>
     */
    public static function rules(): array
    {
        return [
            SocialPlatform::LinkedIn->value => [
                'label'          => 'LinkedIn',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => false,
                'mime_types'     => [self::MIME_JPEG, self::MIME_PNG, 'image/gif'],
                'max_bytes'      => 8 * self::MEGABYTE,
                'max_characters' => 3000,
                'media'          => 'JPEG, PNG or GIF, up to 8 MB.',
            ],
            SocialPlatform::Facebook->value => [
                'label'          => 'Facebook',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => false,
                'mime_types'     => [self::MIME_JPEG, self::MIME_PNG, 'image/gif', 'image/bmp', self::MIME_MP4],
                'max_bytes'      => 1024 * self::MEGABYTE,
                'max_characters' => 63206,
                'media'          => 'JPEG, PNG, GIF, BMP or MP4, up to 1 GB.',
            ],
            SocialPlatform::Instagram->value => [
                'label'          => 'Instagram',
                'media_required' => true,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [self::MIME_JPEG, self::MIME_MP4],
                'max_bytes'      => 100 * self::MEGABYTE,
                'max_characters' => 2200,
                'media'          => 'JPEG or MP4, up to 100 MB.',
            ],
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     media_required: bool,
     *     media_count: int,
     *     fetches_media: bool,
     *     mime_types: list<string>,
     *     max_bytes: int,
     *     max_characters: int,
     *     media: string
     * }
     */
    public static function rule(SocialPlatform $platform): array
    {
        return self::rules()[$platform->value];
    }

    /**
     * The rules as the config endpoint answers with them.
     *
     * The frontend used to keep its own copy of this table, and a limit
     * that changed here left its badge warning about a number the backend
     * no longer enforced. The mapping lives beside the table so the wire
     * names cannot drift from it either.
     *
     * The wording is the backend's: the note names the rule and the
     * remedies name the action, so the badge on an attachment and the
     * refusal at publish time read as one thing.
     *
     * @return array<string, array{
     *     media_required: bool,
     *     media_count: int,
     *     fetches_media: bool,
     *     mime_types: list<string>,
     *     max_bytes: int,
     *     max_text: int,
     *     media_note: string,
     *     media_remedy: string,
     *     video_remedy: string,
     *     size_remedy: string
     * }>
     */
    public static function apiRules(): array
    {
        $rules = [];

        foreach (self::rules() as $platform => $rule) {
            $rules[$platform] = [
                'media_required' => $rule['media_required'],
                'media_count'    => $rule['media_count'],
                'fetches_media'  => $rule['fetches_media'],
                'mime_types'     => $rule['mime_types'],
                'max_bytes'      => $rule['max_bytes'],
                'max_text'       => $rule['max_characters'],
                'media_note'     => $rule['label'] . ' accepts ' . $rule['media'],
                'media_remedy'   => self::remedyForMime(SocialPlatform::from($platform), self::MIME_PNG),
                'video_remedy'   => self::remedyForMime(SocialPlatform::from($platform), self::MIME_MP4),
                'size_remedy'    => self::REMEDY_COMPRESS,
            ];
        }

        return $rules;
    }

    /**
     * How many characters the platform accepts in a post body.
     */
    public static function characterLimit(SocialPlatform $platform): int
    {
        return (int) self::rule($platform)['max_characters'];
    }

    /**
     * Whether the attachment fits the platform.
     *
     * @return array{fits: bool, reasons: list<string>, remedy: ?string}
     */
    public static function check(SocialPlatform $platform, ?string $mimeType, ?int $sizeBytes): array
    {
        $rule = self::rule($platform);
        $reasons = [];
        $remedy = null;

        if ($mimeType === null || $mimeType === '') {
            if ($rule['media_required']) {
                $reasons[] = $rule['label'] . ' cannot publish a post without media.';
                $remedy = self::REMEDY_ATTACH_MEDIA;
            }

            return ['fits' => $reasons === [], 'reasons' => $reasons, 'remedy' => $remedy];
        }

        $mime = strtolower($mimeType);

        if (!in_array($mime, $rule['mime_types'], true)) {
            $reasons[] = $rule['label'] . ' does not accept ' . $mime . '.';
            $remedy = self::remedyForMime($platform, $mime);
        }

        if ($sizeBytes !== null && $sizeBytes > $rule['max_bytes']) {
            $reasons[] = sprintf(
                '%s accepts at most %s, this file is %s.',
                $rule['label'],
                self::humanBytes($rule['max_bytes']),
                self::humanBytes($sizeBytes),
            );
            $remedy = self::REMEDY_COMPRESS;
        }

        return ['fits' => $reasons === [], 'reasons' => $reasons, 'remedy' => $remedy];
    }

    /**
     * Whether the body fits the platform's character limit.
     *
     * Counted in characters, not bytes: the limits are the networks' own
     * character counts.
     *
     * @return array{fits: bool, reasons: list<string>, remedy: ?string}
     */
    public static function checkBody(SocialPlatform $platform, string $body): array
    {
        $rule = self::rule($platform);
        $limit = (int) $rule['max_characters'];
        $length = mb_strlen($body);

        if ($length <= $limit) {
            return ['fits' => true, 'reasons' => [], 'remedy' => null];
        }

        return [
            'fits'    => false,
            'reasons' => [sprintf(
                '%s: %d / %d characters for %s.',
                self::OVER_THE_LIMIT,
                $length,
                $limit,
                $rule['label'],
            )],
            'remedy' => self::REMEDY_SHORTEN,
        ];
    }

    /**
     * The remedy for a file type the platform rejects.
     *
     * Instagram is the case that matters: it takes no PNG at all, and the
     * conversion is one derivative away.
     */
    private static function remedyForMime(SocialPlatform $platform, string $mime): string
    {
        if ($platform === SocialPlatform::Instagram) {
            return str_starts_with($mime, 'video/')
                ? self::REMEDY_CONVERT_VIDEO
                : self::REMEDY_CONVERT_JPEG;
        }

        if ($platform === SocialPlatform::LinkedIn && str_starts_with($mime, 'video/')) {
            return self::REMEDY_CONVERT_VIDEO;
        }

        return self::REMEDY_CONVERT_JPEG;
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= self::MEGABYTE) {
            return round($bytes / self::MEGABYTE, $bytes % self::MEGABYTE === 0 ? 0 : 1) . ' MB';
        }

        return $bytes . ' B';
    }
}
