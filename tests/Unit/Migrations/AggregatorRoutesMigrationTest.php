<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * The migration, as the file returns it.
 *
 * The path is a literal because that is what lets the analyser read the
 * file: the plugin's own `migrationsPath()` is asserted in
 * `SocialPluginTest`.
 */
function aggregatorRoutesMigration()
{
    return require __DIR__ . '/../../../database/migrations/social_000009_add_aggregator_routes.php';
}

/**
 * A post whose copy is keyed by the network, as it was written before
 * the channel existed.
 */
function aPostWithCopyKeyedByNetwork(int $principalId, string $bodies): int
{
    return (int) Capsule::table('social_posts')->insertGetId([
        'principal_id'    => $principalId,
        'body'            => 'The general copy',
        'platform_bodies' => $bodies,
        'status'          => 'draft',
        'source'          => 'manual',
        'timezone'        => 'UTC',
        'created_at'      => '2026-11-02 09:00:00',
        'updated_at'      => '2026-11-02 09:00:00',
    ]);
}

it('gives the accounts and the targets a route', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = createSocialAccount($principalId, 'linkedin', 'organization');

    $row = Capsule::table('social_accounts')->where('id', $account->id)->first();

    expect($row->via)->toBe('direct');
});

it('gives the accounts that were already there the plugin\'s own route', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = createSocialAccount($principalId, 'linkedin', 'organization');

    // The schema as it was before the migration: no route column, and
    // the key that says one account per network.
    Capsule::schema()->table('social_accounts', function (Blueprint $table): void {
        $table->dropUnique(['principal_id', 'via', 'platform', 'external_id']);
    });
    Capsule::schema()->table('social_accounts', function (Blueprint $table): void {
        $table->dropColumn('via');
    });

    aggregatorRoutesMigration()->up();

    $row = Capsule::table('social_accounts')->where('id', $account->id)->first();

    expect($row->via)->toBe('direct');
});

it('lets one network be two accounts on two routes', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $direct = createSocialAccount($principalId, 'linkedin', 'organization', [
        'external_id' => 'urn:li:organization:1',
    ]);

    // The same network, the same account id, another route: another
    // account, with another token.
    $aggregatedId = (int) Capsule::table('social_accounts')->insertGetId([
        'principal_id' => $principalId,
        'connection_id' => $direct->connection_id,
        'platform'     => 'linkedin',
        'via'          => 'zernio',
        'kind'         => 'organization',
        'external_id'  => 'urn:li:organization:1',
        'status'       => 'active',
        'created_at'   => '2026-11-02 09:00:00',
        'updated_at'   => '2026-11-02 09:00:00',
    ]);

    $rows = Capsule::table('social_accounts')
        ->where('principal_id', $principalId)
        ->where('external_id', 'urn:li:organization:1')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('via')->sort()->values()->all())->toBe(['direct', 'zernio'])
        ->and((int) $direct->id)->not->toBe($aggregatedId);
});

it('rewrites the copy\'s keys to the channel\'s names', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $postId = aPostWithCopyKeyedByNetwork($principalId, '{"linkedin":"The LinkedIn copy"}');

    aggregatorRoutesMigration()->up();

    $row = Capsule::table('social_posts')->where('id', $postId)->first();

    expect($row->platform_bodies)->toBe('{"direct:linkedin":"The LinkedIn copy"}');
});

it('rewrites the copy\'s keys once, however often the migration runs', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $postId = aPostWithCopyKeyedByNetwork($principalId, '{"linkedin":"The LinkedIn copy"}');

    $migration = aggregatorRoutesMigration();
    $migration->up();
    $migration->up();

    $row = Capsule::table('social_posts')->where('id', $postId)->first();

    expect($row->platform_bodies)->toBe('{"direct:linkedin":"The LinkedIn copy"}');
});

it('leaves a copy that already names a route alone', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $postId = aPostWithCopyKeyedByNetwork($principalId, '{"zernio:bluesky":"The Bluesky copy"}');

    aggregatorRoutesMigration()->up();

    $row = Capsule::table('social_posts')->where('id', $postId)->first();

    expect($row->platform_bodies)->toBe('{"zernio:bluesky":"The Bluesky copy"}');
});

it('moves the copy\'s keys back when it is rolled back', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $postId = aPostWithCopyKeyedByNetwork($principalId, '{"linkedin":"The LinkedIn copy"}');

    $migration = aggregatorRoutesMigration();
    $migration->up();
    $migration->down();

    $row = Capsule::table('social_posts')->where('id', $postId)->first();

    expect($row->platform_bodies)->toBe('{"linkedin":"The LinkedIn copy"}');
});

it('counts the index name the way the comment does', function () {
    $source = (string) file_get_contents(
        dirname(__DIR__, 3) . '/database/migrations/social_000009_add_aggregator_routes.php',
    );

    // The comment counts the name MySQL is given, and the name is built
    // from the table and its columns. The number was once wrong, and a
    // wrong number in a comment about a limit is the kind of thing
    // nobody notices.
    preg_match('/(\d+) characters — MySQL allows 64 for an index name/', $source, $matches);

    $name = 'social_accounts_principal_id_via_platform_external_id_unique';

    expect($matches)->not->toBeEmpty()
        ->and((int) ($matches[1] ?? 0))->toBe(strlen($name));
});
