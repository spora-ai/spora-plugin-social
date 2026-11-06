<?php

declare(strict_types=1);

use Spora\Plugins\Social\Aggregator\AggregatorAccountSync;
use Spora\Plugins\Social\Aggregator\AggregatorKind;
use Spora\Plugins\Social\Aggregator\AggregatorProvider;
use Spora\Plugins\Social\Aggregator\AggregatorService;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Tests\Support\ZernioRecorder;

/**
 * The aggregator's sync, against a mocked Zernio.
 */
function accountSync(ZernioRecorder $recorder): AggregatorAccountSync
{
    $aggregators = new AggregatorService(
        zernioClient($recorder->client()),
        new Spora\Plugins\Social\Service\SocialConnectionTokens(
            new Spora\Plugins\Social\Service\SocialSettings(socialConfig()),
            new Spora\Plugins\Social\Tests\Support\FakeProviderResolver([]),
            securityManager(),
        ),
    );

    return new AggregatorAccountSync(zernioClient($recorder->client()), $aggregators);
}

/**
 * A Zernio connection with a key.
 */
function zernioConnection(int $principalId): SocialConnection
{
    $connection = new SocialConnection();
    $connection->principal_id = $principalId;
    $connection->provider = AggregatorProvider::Zernio->value;
    $connection->access_token = securityManager()->encrypt('the-key')->toStorageString();
    $connection->save();

    return $connection;
}

it('upserts the accounts the aggregator reports', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $connection = zernioConnection($principalId);

    $sync = accountSync($recorder);
    $result = $sync->read($connection);
    $sync->apply($connection, $result);

    $account = SocialAccount::query()
        ->where('principal_id', $principalId)
        ->where('via', 'zernio')
        ->where('platform', 'bluesky')
        ->first();

    expect($account)->not->toBeNull()
        ->and($account?->external_id)->toBe('id_001')
        ->and($account?->display_name)->toBe('name_002')
        ->and($account?->username)->toBe('name_001')
        ->and($account?->status)->toBe(SocialAccount::STATUS_ACTIVE);
});

it('maps the kind by network, and falls back to the aggregator kind', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $connection = zernioConnection($principalId);

    $sync = accountSync($recorder);
    $result = $sync->read($connection);
    $sync->apply($connection, $result);

    $kindOf = static fn(string $network): ?string => SocialAccount::query()
        ->where('principal_id', $principalId)
        ->where('via', 'zernio')
        ->where('platform', $network)
        ->first()?->kind;

    // Bluesky is a network the plugin does not reach natively, and a
    // Facebook account is a Page even when it is reached through Zernio.
    expect($kindOf('bluesky'))->toBe(SocialAccountKind::Aggregator->value)
        ->and($kindOf('facebook'))->toBe(SocialAccountKind::Page->value)
        ->and($kindOf('twitter'))->toBe(SocialAccountKind::Member->value);
});

it('lets what the aggregator says about the account win over the table', function () {
    // A Facebook account the aggregator calls an organisation is one.
    expect(AggregatorKind::of('facebook', 'organization'))->toBe(SocialAccountKind::Organization->value)
        // A network the aggregator says nothing about falls back to the
        // network's own table.
        ->and(AggregatorKind::of('facebook', null))->toBe(SocialAccountKind::Page->value)
        ->and(AggregatorKind::of('linkedin', null))->toBe(SocialAccountKind::Member->value)
        ->and(AggregatorKind::of('linkedin', 'organisation'))->toBe(SocialAccountKind::Organization->value)
        ->and(AggregatorKind::of('bluesky', null))->toBe(SocialAccountKind::Aggregator->value)
        // A word the plugin does not know is no word at all.
        ->and(AggregatorKind::of('bluesky', 'channel'))->toBe(SocialAccountKind::Aggregator->value);
});

it('fetches the follower count and writes it down', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $connection = zernioConnection($principalId);

    $sync = accountSync($recorder);
    $result = $sync->read($connection);
    $sync->apply($connection, $result);

    $account = SocialAccount::query()
        ->where('principal_id', $principalId)
        ->where('via', 'zernio')
        ->where('platform', 'bluesky')
        ->first();

    expect($account?->followers)->toBe(1234);
});

it('reads the follower count off the account object, and asks for none', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts-with-followers')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $connection = zernioConnection($principalId);

    $sync = accountSync($recorder);
    $result = $sync->read($connection);
    $sync->apply($connection, $result);

    $account = SocialAccount::query()
        ->where('principal_id', $principalId)
        ->where('via', 'zernio')
        ->where('platform', 'bluesky')
        ->first();

    expect($account?->followers)->toBe(1234);

    // One call per account is a call per account, and the number was
    // already in hand.
    expect($recorder->callsTo('follower-stats'))->toBe([]);
});

it('asks for the follower count the account object does not carry', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $connection = zernioConnection($principalId);

    $sync = accountSync($recorder);
    $result = $sync->read($connection);
    $sync->apply($connection, $result);

    $calls = $recorder->callsTo('follower-stats');

    // One call per account, and the parameter is the plural one: the
    // endpoint takes a list of account ids.
    expect($calls)->toHaveCount(3)
        ->and($calls[0]['url'])->toBe('https://zernio.com/api/v1/accounts/follower-stats?accountIds=id_001');
});

it('asks only for the counts the account object does not carry', function () {
    $accounts = json_decode(zernioFixture('accounts-with-followers'), true, 512, JSON_THROW_ON_ERROR);

    // The account the object says nothing about.
    unset($accounts['accounts'][1]['followersCount']);

    $recorder = new ZernioRecorder([
        ['body' => (string) json_encode($accounts)],
        ['body' => zernioFixture('follower-stats')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $connection = zernioConnection($principalId);

    $sync = accountSync($recorder);
    $result = $sync->read($connection);
    $sync->apply($connection, $result);

    $calls = $recorder->callsTo('follower-stats');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['url'])->toBe('https://zernio.com/api/v1/accounts/follower-stats?accountIds=id_002');

    // The count the object carried is the count the account keeps.
    $bluesky = SocialAccount::query()
        ->where('principal_id', $principalId)
        ->where('via', 'zernio')
        ->where('platform', 'bluesky')
        ->first();

    expect($bluesky?->followers)->toBe(1234);
});

it('leaves the follower count empty when the add-on is not there, and does not fail the sync', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts')],
        ['body' => zernioFixture('analytics-addon-required'), 'status' => 402],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $connection = zernioConnection($principalId);

    $sync = accountSync($recorder);
    $result = $sync->read($connection);
    $sync->apply($connection, $result);

    $account = SocialAccount::query()
        ->where('principal_id', $principalId)
        ->where('via', 'zernio')
        ->where('platform', 'bluesky')
        ->first();

    // The account is still an account: only the count is missing.
    expect($account)->not->toBeNull()
        ->and($account?->followers)->toBeNull();

    // And the reason is on the account, because the answer is the
    // aggregator's plan and not a scope of ours.
    $reporting = Spora\Plugins\Social\Service\AccountReporting::of($account, []);

    expect($reporting['can_report'])->toBeFalse()
        ->and($reporting['report_note'])->toContain('add-on');
});

it('marks an account the aggregator no longer reports disconnected, and does not delete it', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $connection = zernioConnection($principalId);

    // An account Zernio used to report and does not any more.
    $gone = createSocialAccount($principalId, 'threads', 'aggregator', [
        'connection' => $connection,
        'external_id' => 'id_009',
        'via'        => 'zernio',
    ]);

    $sync = accountSync($recorder);
    $result = $sync->read($connection);
    $sync->apply($connection, $result);

    expect($gone->fresh()?->status)->toBe(SocialAccount::STATUS_DISCONNECTED)
        ->and(SocialAccount::query()->find($gone->id))->not->toBeNull();
});

it('never creates a connection', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('accounts')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
        ['body' => zernioFixture('follower-stats')],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $connection = zernioConnection($principalId);

    $before = SocialConnection::query()->count();

    $sync = accountSync($recorder);
    $result = $sync->read($connection);
    $sync->apply($connection, $result);

    // The key is the operator's. A sync that made a connection would be a
    // plugin that writes credentials.
    expect(SocialConnection::query()->count())->toBe($before);
});
