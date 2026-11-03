<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * The tool that lists the connections was renamed.
 *
 * The class was `SocialAccountsTool` and is now `SocialConnectionsTool`:
 * a connection is one login that covers several accounts, so the name
 * that fits the class is the one it has now.
 *
 * The host's tables carry the **class** name, not the wire name, and the
 * host matches the enabled tools by class. A row that still carries the
 * old class therefore points at a class that does not exist: the tool is
 * never offered to the agent, and the tools that name it in their
 * descriptions — `social_post` sends the agent to `social_connections`
 * to find the account ids — send it to a tool it cannot call.
 *
 * The rows move with the class. Where a scope already has a row for the
 * new class, the stale row is dropped instead: the live row is the one
 * the operator configured.
 */
return new class extends Migration {
    private const OLD_CLASS = 'Spora\\Plugins\\Social\\Tools\\SocialAccountsTool';

    private const NEW_CLASS = 'Spora\\Plugins\\Social\\Tools\\SocialConnectionsTool';

    private const OLD_NAME = 'social_accounts';

    private const NEW_NAME = 'social_connections';

    /**
     * The tables the class name is stored in, with the columns that
     * scope a row.
     *
     * The scope is what makes a row unique: one tool per agent, one per
     * principal, one override per operation.
     *
     * @var array<string, list<string>>
     */
    private const SCOPES = [
        'agent_tools'                    => ['agent_id'],
        'agent_tool_overrides'           => ['agent_id'],
        'agent_tool_operation_overrides' => ['agent_id', 'operation'],
        'tool_user_settings'             => ['principal_id'],
        'tool_configurations'            => [],
    ];

    /**
     * The tables that also carry the wire name of the tool.
     *
     * @var list<string>
     */
    private const NAME_TABLES = ['agent_tools', 'tool_configurations'];

    public function up(): void
    {
        $this->move(self::OLD_CLASS, self::NEW_CLASS, self::OLD_NAME, self::NEW_NAME);
    }

    public function down(): void
    {
        $this->move(self::NEW_CLASS, self::OLD_CLASS, self::NEW_NAME, self::OLD_NAME);
    }

    private function move(string $fromClass, string $toClass, string $fromName, string $toName): void
    {
        $schema = Capsule::schema();

        foreach (self::SCOPES as $table => $scopes) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $this->moveRows($table, $scopes, $fromClass, $toClass, $fromName, $toName);
        }
    }

    /**
     * @param list<string> $scopes
     */
    private function moveRows(
        string $table,
        array $scopes,
        string $fromClass,
        string $toClass,
        string $fromName,
        string $toName,
    ): void {
        $rows = Capsule::table($table)->where('tool_class', $fromClass)->get();

        foreach ($rows as $row) {
            $existing = Capsule::table($table)->where('tool_class', $toClass);

            foreach ($scopes as $scope) {
                $existing->where($scope, $row->{$scope});
            }

            if ($existing->exists()) {
                Capsule::table($table)->where('id', $row->id)->delete();

                continue;
            }

            $update = ['tool_class' => $toClass];

            if (in_array($table, self::NAME_TABLES, true) && $row->tool_name === $fromName) {
                $update['tool_name'] = $toName;
            }

            Capsule::table($table)->where('id', $row->id)->update($update);
        }
    }
};
