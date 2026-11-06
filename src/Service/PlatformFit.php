<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialChannel;

/**
 * What each channel accepts: the media rules and the character limit.
 *
 * A rule also carries the characters the network shows before it hides
 * the rest behind a "see more": a post that reads well in full can still say
 * nothing in the first line, and that is what a reader sees.
 *
 * One table, because the rules are read in three places — the composer
 * badge on an attachment, the publish-time guard, and the prose the agent
 * reads — and three copies of a size cap would drift.
 *
 * The table is keyed by the channel's key, not by the network: the same
 * network reached through two routes is two accounts, and the aggregator's
 * limits are the aggregator's own.
 *
 * The verdict names the remedy, not just the failure: an operator can act
 * on "Convert to JPEG" and not on a platform's error text. **The remedy
 * rides on the row**, because the networks' rules differ — Instagram
 * takes no PNG and no GIF, Bluesky takes a GIF and no MOV, and a row that
 * borrowed the network's remedy would name a conversion the network does
 * not need.
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
 *
 * **The aggregator's rows are a copy of Zernio's own documentation**, one
 * row per platform page, and they will rot: Zernio changes a limit and
 * the copy is behind until someone reads the changelog. A stale row
 * produces a wrong warning and not a failed post — the publish-time
 * refusal is Zernio's own error, which the target carries.
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

    private const GIGABYTE = 1073741824;

    private const MIME_JPEG = 'image/jpeg';
    private const MIME_PNG = 'image/png';
    private const MIME_GIF = 'image/gif';
    private const MIME_WEBP = 'image/webp';
    private const MIME_MP4 = 'video/mp4';
    private const MIME_MOV = 'video/quicktime';
    private const MIME_AVI = 'video/x-msvideo';
    private const MIME_WEBM = 'video/webm';

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
     *     visible_chars: int,
     *     media: string,
     *     media_remedy: string,
     *     video_remedy: string,
     *     size_remedy: string,
     *     counting_note: ?string
     * }>
     */
    public static function rules(): array
    {
        return [
            'direct:linkedin' => [
                'label'          => 'LinkedIn',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => false,
                'mime_types'     => [self::MIME_JPEG, self::MIME_PNG, self::MIME_GIF],
                'max_bytes'      => 8 * self::MEGABYTE,
                'max_characters' => 3000,
                'visible_chars'  => 200,
                'media'          => 'JPEG, PNG or GIF, up to 8 MB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'direct:facebook' => [
                'label'          => 'Facebook',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => false,
                'mime_types'     => [
                    self::MIME_JPEG,
                    self::MIME_PNG,
                    self::MIME_GIF,
                    'image/bmp',
                    self::MIME_MP4,
                ],
                'max_bytes'      => 1024 * self::MEGABYTE,
                'max_characters' => 63206,
                'visible_chars'  => 125,
                'media'          => 'JPEG, PNG, GIF, BMP or MP4, up to 1 GB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_JPEG,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'direct:instagram' => [
                'label'          => 'Instagram',
                'media_required' => true,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [self::MIME_JPEG, self::MIME_MP4],
                'max_bytes'      => 100 * self::MEGABYTE,
                'max_characters' => 2200,
                'visible_chars'  => 125,
                'media'          => 'JPEG or MP4, up to 100 MB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:twitter' => [
                'label'          => 'X',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [
                    self::MIME_JPEG,
                    self::MIME_PNG,
                    self::MIME_WEBP,
                    self::MIME_GIF,
                    self::MIME_MP4,
                    self::MIME_MOV,
                ],
                'max_bytes'      => 512 * self::MEGABYTE,
                'max_characters' => 280,
                'visible_chars'  => 280,
                'media'          => 'JPEG, PNG, WebP or GIF up to 5 MB, or an MP4 or MOV video up to 512 MB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => 'X counts a URL as 23 characters whatever its length, and an emoji as 2, '
                    . 'so the number of characters in the body is not the number X counts.',
            ],
            'zernio:instagram' => [
                'label'          => 'Instagram',
                'media_required' => true,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [self::MIME_JPEG, self::MIME_PNG, self::MIME_MP4, self::MIME_MOV],
                'max_bytes'      => 300 * self::MEGABYTE,
                'max_characters' => 2200,
                'visible_chars'  => 125,
                'media'          => 'JPEG or PNG up to 8 MB, or an MP4 or MOV video up to 300 MB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:facebook' => [
                'label'          => 'Facebook',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [
                    self::MIME_JPEG,
                    self::MIME_PNG,
                    self::MIME_GIF,
                    self::MIME_MP4,
                    self::MIME_MOV,
                ],
                'max_bytes'      => 4 * self::GIGABYTE,
                'max_characters' => 63206,
                'visible_chars'  => 480,
                'media'          => 'JPEG, PNG or GIF up to 4 MB, or an MP4 or MOV video up to 4 GB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:linkedin' => [
                'label'          => 'LinkedIn',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [
                    self::MIME_JPEG,
                    self::MIME_PNG,
                    self::MIME_GIF,
                    self::MIME_MP4,
                    self::MIME_MOV,
                    self::MIME_AVI,
                ],
                'max_bytes'      => 5 * self::GIGABYTE,
                'max_characters' => 3000,
                'visible_chars'  => 200,
                'media'          => 'JPEG, PNG or GIF up to 8 MB, or an MP4, MOV or AVI video up to 5 GB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:tiktok' => [
                'label'          => 'TikTok',
                'media_required' => true,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [
                    self::MIME_JPEG,
                    self::MIME_PNG,
                    self::MIME_WEBP,
                    self::MIME_MP4,
                    self::MIME_MOV,
                    self::MIME_WEBM,
                ],
                'max_bytes'      => 4 * self::GIGABYTE,
                'max_characters' => 2200,
                'visible_chars'  => 2200,
                'media'          => 'JPEG, PNG or WebP up to 20 MB, or an MP4, MOV or WebM video up to 4 GB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:youtube' => [
                'label'          => 'YouTube',
                'media_required' => true,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [
                    self::MIME_MP4,
                    self::MIME_MOV,
                    self::MIME_AVI,
                    'video/x-ms-wmv',
                    'video/x-flv',
                    'video/3gpp',
                    self::MIME_WEBM,
                ],
                'max_bytes'      => 256 * self::GIGABYTE,
                'max_characters' => 5000,
                'visible_chars'  => 5000,
                'media'          => 'an MP4, MOV, AVI, WMV, FLV, 3GP or WebM video up to 256 GB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:pinterest' => [
                'label'          => 'Pinterest',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [
                    self::MIME_JPEG,
                    self::MIME_PNG,
                    self::MIME_WEBP,
                    self::MIME_GIF,
                    self::MIME_MP4,
                    self::MIME_MOV,
                ],
                'max_bytes'      => 2 * self::GIGABYTE,
                'max_characters' => 800,
                'visible_chars'  => 800,
                'media'          => 'JPEG, PNG, WebP or GIF up to 32 MB, or an MP4 or MOV video up to 2 GB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:reddit' => [
                'label'          => 'Reddit',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [
                    self::MIME_JPEG,
                    self::MIME_PNG,
                    self::MIME_GIF,
                    self::MIME_MP4,
                ],
                'max_bytes'      => 1 * self::GIGABYTE,
                'max_characters' => 40000,
                'visible_chars'  => 40000,
                'media'          => 'JPEG, PNG or GIF up to 20 MB, or an MP4 video up to 1 GB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:bluesky' => [
                'label'          => 'Bluesky',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [
                    self::MIME_JPEG,
                    self::MIME_PNG,
                    self::MIME_WEBP,
                    self::MIME_GIF,
                    self::MIME_MP4,
                ],
                'max_bytes'      => 50 * self::MEGABYTE,
                'max_characters' => 300,
                'visible_chars'  => 300,
                'media'          => 'JPEG, PNG, WebP or GIF up to 1 MB, or an MP4 video up to 50 MB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:threads' => [
                'label'          => 'Threads',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [self::MIME_JPEG, self::MIME_PNG, self::MIME_MP4],
                'max_bytes'      => 1 * self::GIGABYTE,
                'max_characters' => 500,
                'visible_chars'  => 500,
                'media'          => 'JPEG or PNG up to 8 MB, or an MP4 video up to 1 GB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:googlebusiness' => [
                'label'          => 'Google Business',
                'media_required' => false,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [self::MIME_JPEG, self::MIME_PNG],
                'max_bytes'      => 5 * self::MEGABYTE,
                'max_characters' => 1500,
                'visible_chars'  => 1500,
                'media'          => 'JPEG or PNG up to 5 MB. No video.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
            'zernio:snapchat' => [
                'label'          => 'Snapchat',
                'media_required' => true,
                'media_count'    => self::MEDIA_PER_POST,
                'fetches_media'  => true,
                'mime_types'     => [self::MIME_JPEG, self::MIME_PNG, self::MIME_MP4],
                'max_bytes'      => 500 * self::MEGABYTE,
                'max_characters' => 160,
                'visible_chars'  => 160,
                'media'          => 'JPEG or PNG up to 20 MB, or an MP4 video up to 500 MB.',
                'media_remedy'   => self::REMEDY_CONVERT_JPEG,
                'video_remedy'   => self::REMEDY_CONVERT_VIDEO,
                'size_remedy'    => self::REMEDY_COMPRESS,
                'counting_note'  => null,
            ],
        ];
    }

    /**
     * The channels the plugin has rules for.
     *
     * A channel without a row is one the plugin does not offer, and the
     * composer says so rather than guessing.
     *
     * @return list<SocialChannel>
     */
    public static function channels(): array
    {
        $channels = [];

        foreach (array_keys(self::rules()) as $key) {
            $channel = SocialChannel::fromKey($key);

            if ($channel !== null) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * The channels the plugin reaches on its own route.
     *
     * The aggregator's channels are the other ones: the same networks,
     * reached through somebody else's API, with that API's limits.
     *
     * @return list<SocialChannel>
     */
    public static function nativeChannels(): array
    {
        return array_values(array_filter(
            self::channels(),
            static fn(SocialChannel $channel): bool => $channel->isDirect(),
        ));
    }

    /**
     * The rule of one channel.
     *
     * @return array{
     *     label: string,
     *     media_required: bool,
     *     media_count: int,
     *     fetches_media: bool,
     *     mime_types: list<string>,
     *     max_bytes: int,
     *     max_characters: int,
     *     visible_chars: int,
     *     media: string,
     *     media_remedy: string,
     *     video_remedy: string,
     *     size_remedy: string,
     *     counting_note: ?string
     * }
     */
    public static function rule(SocialChannel $channel): array
    {
        $rule = self::rules()[$channel->key()] ?? null;

        if ($rule === null) {
            throw SocialException::validation('There are no rules for the channel ' . $channel->key() . '.');
        }

        return $rule;
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
     * The label and the route travel with the rule, so the network names
     * live in one place — the frontend stops keeping its own list of them.
     *
     * @return array<string, array{
     *     label: string,
     *     network: string,
     *     via: string,
     *     media_required: bool,
     *     media_count: int,
     *     fetches_media: bool,
     *     mime_types: list<string>,
     *     max_bytes: int,
     *     max_text: int,
     *     visible_chars: int,
     *     media_note: string,
     *     media_remedy: string,
     *     video_remedy: string,
     *     size_remedy: string,
     *     counting_note: ?string
     * }>
     */
    public static function apiRules(): array
    {
        $rules = [];

        foreach (self::channels() as $channel) {
            $rule = self::rule($channel);

            $rules[$channel->key()] = [
                'label'          => $rule['label'],
                'network'        => $channel->network,
                'via'            => $channel->via,
                'media_required' => $rule['media_required'],
                'media_count'    => $rule['media_count'],
                'fetches_media'  => $rule['fetches_media'],
                'mime_types'     => $rule['mime_types'],
                'max_bytes'      => $rule['max_bytes'],
                'max_text'       => $rule['max_characters'],
                'visible_chars'  => $rule['visible_chars'],
                'media_note'     => $rule['label'] . ' accepts ' . $rule['media'],
                'media_remedy'   => $rule['media_remedy'],
                'video_remedy'   => $rule['video_remedy'],
                'size_remedy'    => $rule['size_remedy'],
                'counting_note'  => $rule['counting_note'],
            ];
        }

        return $rules;
    }

    /**
     * How many characters the channel accepts in a post body.
     */
    public static function characterLimit(SocialChannel $channel): int
    {
        return (int) self::rule($channel)['max_characters'];
    }

    /**
     * The text a post goes out with: its body, and its hashtags under it.
     *
     * This is the authority for the composition — the frontend mirrors it,
     * so the preview in the composer and the post on the network read the
     * same.
     *
     * @param list<string> $hashtags
     */
    public static function composeText(string $body, array $hashtags): string
    {
        $tags = array_map(
            static fn(string $hashtag): string => '#' . $hashtag,
            $hashtags,
        );

        if ($tags === []) {
            return $body;
        }

        $text = implode(' ', $tags);

        return $body === '' ? $text : $body . "\n\n" . $text;
    }

    /**
     * Whether the attachment fits the channel.
     *
     * @return array{fits: bool, reasons: list<string>, remedy: ?string}
     */
    public static function check(SocialChannel $channel, ?string $mimeType, ?int $sizeBytes): array
    {
        $rule = self::rule($channel);
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
            $remedy = self::remedyForMime($rule, $mime);
        }

        if ($sizeBytes !== null && $sizeBytes > $rule['max_bytes']) {
            $reasons[] = sprintf(
                '%s accepts at most %s, this file is %s.',
                $rule['label'],
                self::humanBytes($rule['max_bytes']),
                self::humanBytes($sizeBytes),
            );
            $remedy = $rule['size_remedy'];
        }

        return ['fits' => $reasons === [], 'reasons' => $reasons, 'remedy' => $remedy];
    }

    /**
     * Whether the body fits the channel's character limit.
     *
     * Counted in characters, not bytes: the limits are the networks' own
     * character counts.
     *
     * A row that carries a counting note is not judged on the count at
     * all: the network counts differently, and a number the plugin cannot
     * compute is worse than no number. The network refuses the post, and
     * its own message lands on the target.
     *
     * @return array{fits: bool, reasons: list<string>, remedy: ?string}
     */
    public static function checkBody(SocialChannel $channel, string $body): array
    {
        $rule = self::rule($channel);

        if ($rule['counting_note'] !== null) {
            return ['fits' => true, 'reasons' => [], 'remedy' => null];
        }

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
     * The remedy for a file type the channel rejects.
     *
     * The row carries it: a video the network will not take is a
     * different conversion from an image it will not take, and the
     * networks differ on both.
     *
     * @param array<string, mixed> $rule
     */
    private static function remedyForMime(array $rule, string $mime): string
    {
        return str_starts_with($mime, 'video/')
            ? (string) $rule['video_remedy']
            : (string) $rule['media_remedy'];
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= self::GIGABYTE) {
            return round($bytes / self::GIGABYTE, $bytes % self::GIGABYTE === 0 ? 0 : 1) . ' GB';
        }

        if ($bytes >= self::MEGABYTE) {
            return round($bytes / self::MEGABYTE, $bytes % self::MEGABYTE === 0 ? 0 : 1) . ' MB';
        }

        return $bytes . ' B';
    }
}
