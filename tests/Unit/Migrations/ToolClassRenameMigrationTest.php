<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * The migration, as the file returns it.
 *
 * The file returns an anonymous class, so the type of the `require` is what
 * the analyser infers from it — the helper hands the migration back as it is
 * and the caller gets `up()` and `down()` without an annotation.
 *
 * The path is a literal because that is what lets the analyser read the file:
 * the plugin's own `migrationsPath()` is asserted in `SocialPluginTest`.
 */
function toolClassMigration()
{
    return require __DIR__ . '/../../../database/migrations/social_000008_rename_accounts_tool_class.php';
}

/**
 * The class the tool had before it was renamed, and the one it has now.
 *
 * @return array{0: string, 1: string}
 */
function theTwoToolClasses(): array
{
    return [
        'Spora\\Plugins\\Social\\Tools\\SocialAccountsTool',
        'Spora\\Plugins\\Social\\Tools\\SocialConnectionsTool',
    ];
}

/**
 * An agent that has the stale class enabled.
 *
 * @return array{agentId: int, principalId: int}
 */
function anAgentWithTheStaleTool(): array
{
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $agentId = createAgentForPrincipal($principalId);

    [$stale] = theTwoToolClasses();

    Capsule::table('agent_tools')->insert([
        'agent_id'   => $agentId,
        'tool_class' => $stale,
        'tool_name'  => 'social_accounts',
    ]);

    return ['agentId' => $agentId, 'principalId' => $principalId];
}

it('moves the enabled tool of an agent to the class that exists', function () {
    ['agentId' => $agentId] = anAgentWithTheStaleTool();

    [, $live] = theTwoToolClasses();

    toolClassMigration()->up();

    $row = Capsule::table('agent_tools')->where('agent_id', $agentId)->first();

    expect($row->tool_class)->toBe($live)
        ->and($row->tool_name)->toBe('social_connections');
});

it('keeps the row the operator configured when the agent already has the new class', function () {
    ['agentId' => $agentId] = anAgentWithTheStaleTool();

    [$stale, $live] = theTwoToolClasses();

    Capsule::table('agent_tools')->insert([
        'agent_id'   => $agentId,
        'tool_class' => $live,
        'tool_name'  => 'social_connections',
    ]);

    toolClassMigration()->up();

    $rows = Capsule::table('agent_tools')->where('agent_id', $agentId)->get();

    // The live row is the one the operator configured; the stale one is
    // dropped, because the table holds one row per agent and class.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->tool_class)->toBe($live);
});

it('moves the overrides and the settings with the class', function () {
    ['agentId' => $agentId, 'principalId' => $principalId] = anAgentWithTheStaleTool();

    [$stale, $live] = theTwoToolClasses();

    Capsule::table('agent_tool_overrides')->insert([
        'agent_id'   => $agentId,
        'tool_class' => $stale,
        'settings'   => '{}',
    ]);

    Capsule::table('agent_tool_operation_overrides')->insert([
        'agent_id'   => $agentId,
        'tool_class' => $stale,
        'operation'  => 'list',
        'enabled'    => 1,
    ]);

    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $principalId,
        'tool_class'   => $stale,
        'settings'     => '{}',
    ]);

    Capsule::table('tool_configurations')->insert([
        'tool_class' => $stale,
        'tool_name'  => 'social_accounts',
        'settings'   => '{}',
    ]);

    toolClassMigration()->up();

    expect(Capsule::table('agent_tool_overrides')->where('agent_id', $agentId)->value('tool_class'))->toBe($live)
        ->and(Capsule::table('agent_tool_operation_overrides')->where('agent_id', $agentId)->value('tool_class'))->toBe($live)
        ->and(Capsule::table('tool_user_settings')->where('principal_id', $principalId)->value('tool_class'))->toBe($live)
        ->and(Capsule::table('tool_configurations')->where('tool_class', $live)->value('tool_name'))->toBe('social_connections');
});

it('leaves every other tool alone', function () {
    $agentId = createAgentForPrincipal(createUserPrincipal(bootAuth(bootAuthLayer())));

    Capsule::table('agent_tools')->insert([
        'agent_id'   => $agentId,
        'tool_class' => 'Spora\\Tools\\CalculatorTool',
        'tool_name'  => 'calculator',
    ]);

    toolClassMigration()->up();

    $row = Capsule::table('agent_tools')->where('agent_id', $agentId)->first();

    expect($row->tool_class)->toBe('Spora\\Tools\\CalculatorTool');
});

it('rewrites nothing on a second run', function () {
    ['agentId' => $agentId] = anAgentWithTheStaleTool();

    [, $live] = theTwoToolClasses();

    $migration = toolClassMigration();
    $migration->up();
    $migration->up();

    $rows = Capsule::table('agent_tools')->where('agent_id', $agentId)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->tool_class)->toBe($live);
});

it('rewrites the rows back when it is rolled back', function () {
    ['agentId' => $agentId] = anAgentWithTheStaleTool();

    [$stale] = theTwoToolClasses();

    $migration = toolClassMigration();
    $migration->up();
    $migration->down();

    $row = Capsule::table('agent_tools')->where('agent_id', $agentId)->first();

    expect($row->tool_class)->toBe($stale)
        ->and($row->tool_name)->toBe('social_accounts');
});
