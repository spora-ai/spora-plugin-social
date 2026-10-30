<?php

declare(strict_types=1);

/**
 * The first thing an operator reads when the live check cannot run.
 *
 * `bin/live` is a script, so the only way to test what it prints is to
 * run it: the text it puts in front of the operator is the contract.
 */
it('prints the variables the harness reads and one redirect uri per provider', function () {
    $root = dirname(__DIR__, 2);

    // The live variables must not leak in from the developer's shell: the
    // run under test has to be the one where nothing is configured.
    $environment = [];

    foreach (getenv() as $name => $value) {
        if (!str_starts_with($name, 'SPORA_LIVE_')) {
            $environment[$name] = $value;
        }
    }

    $process = proc_open(
        [PHP_BINARY, $root . '/bin/live', '--urls'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
        $environment,
    );

    expect($process)->not->toBeFalse();

    $output = (string) stream_get_contents($pipes[1]);

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    // The hint names the variables the harness actually reads: Meta's one
    // login, so one pair of credentials for both networks.
    expect($output)->toContain('SPORA_LIVE_META_CLIENT_ID')
        ->and($output)->toContain('SPORA_LIVE_META_CLIENT_SECRET')
        ->and($output)->not->toContain('SPORA_LIVE_FACEBOOK_CLIENT_ID')
        ->and($output)->not->toContain('SPORA_LIVE_INSTAGRAM_CLIENT_ID');

    // The Meta callback is registered once and covers both networks.
    expect(substr_count($output, '/oauth/meta/callback'))->toBe(1)
        ->and($output)->toContain('/oauth/linkedin/callback')
        ->and($output)->toContain('covers Instagram and Facebook');
});
