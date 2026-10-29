<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\SkippedTest;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Tests\Live\Support\LiveCheck;
use Spora\Plugins\Social\Tests\Live\Support\LiveCheckFailed;
use Spora\Plugins\Social\Tests\Live\Support\LiveContext;
use Spora\Plugins\Social\Tests\Live\Support\LiveContract;
use Spora\Plugins\Social\Tests\Live\Support\LiveGuzzleRecorder;
use Spora\Plugins\Social\Tests\Live\Support\LiveReport;
use Spora\Plugins\Social\Tests\Live\Support\LiveSecrets;

/**
 * The harness checking itself.
 *
 * These are the checks that need no credentials, and they are the ones that
 * make the rest trustworthy: a suite that skips quietly is worse than no
 * suite, and a failure message that carries a token puts the token in the
 * operator's terminal history.
 */

/**
 * The entry of the report with the given name.
 *
 * @return array<string, mixed>|null
 */
function liveReportEntry(string $name): ?array
{
    $decoded = json_decode((string) file_get_contents(LiveReport::defaultPath()), true);

    if (!is_array($decoded)) {
        return null;
    }

    $checks = $decoded['checks'] ?? null;

    if (!is_array($checks)) {
        return null;
    }

    foreach ($checks as $check) {
        if (is_array($check) && ($check['name'] ?? null) === $name) {
            return $check;
        }
    }

    return null;
}

it('skips a check whose requirement is missing and records the skip', function () {
    $context = LiveContext::instance();
    $name = 'the harness skip path';

    $check = new LiveCheck(
        $context,
        'harness',
        SocialPlatform::LinkedIn,
        $name,
        LiveCheck::CREDENTIALS,
        'the harness asked for a requirement that is not there',
    );

    $ran = false;
    $skipped = null;

    try {
        $check->run(function () use (&$ran): void {
            $ran = true;
        });
    } catch (SkippedTest $skip) {
        $skipped = $skip;
    }

    LiveContract::holds($skipped !== null, 'the check did not skip');
    LiveContract::holds($ran === false, 'the body of a skipped check ran anyway');

    $entry = liveReportEntry($name);

    LiveContract::holds($entry !== null, 'the skip was not recorded in the report');
    LiveContract::holds(
        ($entry['outcome'] ?? null) === LiveReport::SKIPPED,
        'the skip was not recorded as a skip',
    );
    LiveContract::holds(
        str_contains((string) ($entry['detail'] ?? ''), 'the harness asked for a requirement'),
        'the recorded skip does not carry the reason it was skipped',
    );
});

it('records a failure with the answer that broke the contract', function () {
    $context = LiveContext::instance();
    $name = 'the harness failure path';

    // The label says what the entry is: this failure is the harness
    // proving that it records failures, so the runner does not read it as a
    // broken check against a platform.
    $check = new LiveCheck($context, 'harness-proof', SocialPlatform::LinkedIn, $name, LiveCheck::NOTHING);

    $failed = null;

    try {
        $check->run(function (): void {
            throw new LiveCheckFailed('the platform answered with a shape nobody expected');
        });
    } catch (LiveCheckFailed $e) {
        $failed = $e;
    }

    LiveContract::holds($failed !== null, 'the failing check did not fail');
    LiveContract::holds(
        str_contains($failed->getMessage(), 'a shape nobody expected'),
        'the failure did not carry the answer that broke the contract',
    );

    $entry = liveReportEntry($name);

    LiveContract::holds($entry !== null, 'the failure was not recorded in the report');
    LiveContract::holds(
        ($entry['outcome'] ?? null) === LiveReport::FAILED,
        'the failure was not recorded as a failure',
    );
});

it('takes a token, a client secret and an authorization code out of what it writes', function () {
    $secrets = new LiveSecrets();

    $secrets->remember('a-client-secret-value');
    $secrets->remember('an-access-token-value');
    $secrets->remember('an-authorization-code');

    $redacted = $secrets->redact(
        'the exchange failed: https://graph.facebook.com/v26.0/me?access_token=an-access-token-value '
        . '&client_secret=a-client-secret-value&code=an-authorization-code',
    );

    LiveContract::holds(
        !str_contains($redacted, 'an-access-token-value'),
        'the access token survived the redaction',
    );
    LiveContract::holds(
        !str_contains($redacted, 'a-client-secret-value'),
        'the client secret survived the redaction',
    );
    LiveContract::holds(
        !str_contains($redacted, 'an-authorization-code'),
        'the authorization code survived the redaction',
    );
});

it('takes a bearer token out of a message it never held', function () {
    $secrets = new LiveSecrets();

    $redacted = $secrets->redact('the platform answered: Authorization: Bearer EAAGsomethinglongandsecret');

    LiveContract::holds(
        !str_contains($redacted, 'EAAGsomethinglongandsecret'),
        'a bearer token survived the redaction',
    );
});

it('writes no secret into the report', function () {
    $context = LiveContext::instance();
    $secret = 'the-secret-the-harness-registered';

    $context->secrets->remember($secret);
    $context->report->record('harness', 'the harness report check', LiveReport::PASSED, 'nothing to see: ' . $secret);

    $contents = (string) file_get_contents(LiveReport::defaultPath());

    LiveContract::holds(
        !str_contains($contents, $secret),
        'the report carries a secret it was given',
    );
});

it('scrubs the credential fields of a recorded oauth call', function () {
    $recorder = new LiveGuzzleRecorder(new LiveSecrets());

    $recorder->client(new MockHandler([
        new Response(200, [], '{"access_token":"the-issued-token"}'),
    ]))->request('POST', 'https://graph.facebook.com/v26.0/oauth/access_token', [
        'form_params' => [
            'client_id'     => 'the-client-id',
            'client_secret' => 'the-client-secret',
            'grant_type'    => 'authorization_code',
            'code'          => 'the-authorization-code',
        ],
    ]);

    $calls = $recorder->calls();

    LiveContract::holds($calls !== [], 'the call was not recorded');

    $form = $calls[0]['form'];

    LiveContract::holds(
        !str_contains((string) ($form['client_secret'] ?? ''), 'the-client-secret'),
        'the client secret survived in the recorded form',
    );
    LiveContract::holds(
        !str_contains((string) ($form['code'] ?? ''), 'the-authorization-code'),
        'the authorization code survived in the recorded form',
    );
    LiveContract::holds(
        ($form['grant_type'] ?? '') === 'authorization_code',
        'the grant type was scrubbed, and the checks need it',
    );
    LiveContract::holds(
        !str_contains($calls[0]['request'], 'the-authorization-code'),
        'the authorization code survived in the recorded request body',
    );
    LiveContract::holds(
        !str_contains($calls[0]['body'], 'the-issued-token'),
        'the issued token survived in the recorded answer',
    );
});
