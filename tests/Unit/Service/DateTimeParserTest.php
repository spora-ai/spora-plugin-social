<?php

declare(strict_types=1);

use Spora\Plugins\Social\Service\DateTimeParser;

it('reads a wall-clock time in the named zone', function () {
    $parsed = DateTimeParser::toUtc('2026-11-02 09:00', 'Europe/Berlin');

    expect($parsed?->toIso8601String())->toBe('2026-11-02T08:00:00+00:00');
});

it('keeps a time that carries its own offset where it is', function () {
    $parsed = DateTimeParser::toUtc('2026-11-02T09:00:00+05:00', 'Europe/Berlin');

    expect($parsed?->toIso8601String())->toBe('2026-11-02T04:00:00+00:00');
});

it('keeps a zulu time where it is', function () {
    $parsed = DateTimeParser::toUtc('2026-11-02T09:00:00Z', 'Europe/Berlin');

    expect($parsed?->toIso8601String())->toBe('2026-11-02T09:00:00+00:00');
});

it('falls back to utc for a zone php does not know', function () {
    $parsed = DateTimeParser::toUtc('2026-11-02 09:00', 'Not/AZone');

    expect($parsed?->toIso8601String())->toBe('2026-11-02T09:00:00+00:00')
        ->and(DateTimeParser::isKnownZone('Not/AZone'))->toBeFalse()
        ->and(DateTimeParser::isKnownZone('Europe/Berlin'))->toBeTrue();
});

it('reads nothing as nothing', function () {
    expect(DateTimeParser::toUtc('', 'UTC'))->toBeNull()
        ->and(DateTimeParser::toUtc('   ', 'UTC'))->toBeNull();
});

it('computes now in utc, whatever the host timezone is', function () {
    expect(DateTimeParser::nowUtc()->getTimezone()->getName())->toBe('UTC');
});
