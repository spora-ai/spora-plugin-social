<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the datetimes the plugin is given.
 *
 * Two cases, and they are not the same thing:
 *
 * - a value that carries its own offset (or a `Z`) is **absolute**. The
 *   named zone does not move it: the caller said exactly when.
 * - a value without one is a **wall-clock time in the named zone**. That
 *   is what an agent means by "09:00 Europe/Berlin", and reading it in
 *   the host's PHP timezone instead puts the post hours away.
 *
 * Everything is returned in UTC, because that is what the columns hold.
 */
final class DateTimeParser
{
    /**
     * A moment in UTC, or null when the value cannot be read.
     */
    public static function toUtc(string $raw, string $timezone = 'UTC'): ?Carbon
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, self::zone($timezone))->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A named zone, or UTC when the name is not one.
     */
    public static function zone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone === '' ? 'UTC' : $timezone);
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * Now, in UTC.
     *
     * Every boundary in the plugin is compared with a UTC column, so it
     * has to be computed in UTC: the host's PHP timezone would shift a
     * reading window by the offset.
     */
    public static function nowUtc(): Carbon
    {
        return Carbon::now('UTC');
    }

    /**
     * Whether the zone name is one PHP knows.
     */
    public static function isKnownZone(string $timezone): bool
    {
        if ($timezone === '') {
            return false;
        }

        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
