<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * What the aggregator's plan carries.
 *
 * An aggregator's numbers are an add-on on its plan, not a scope Spora
 * granted, so the answer to "can this account report?" cannot come from
 * the granted scopes. The flag lives on the connection that holds the
 * key: it is a property of the plan, and the plan is the connection's.
 *
 * Null on the OAuth connections, which have no plan and no add-on.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('social_connections') && !$schema->hasColumn('social_connections', 'analytics_available')) {
            $schema->table('social_connections', function (Blueprint $table): void {
                $table->boolean('analytics_available')->nullable()->after('scopes');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('social_connections') && $schema->hasColumn('social_connections', 'analytics_available')) {
            $schema->table('social_connections', function (Blueprint $table): void {
                $table->dropColumn('analytics_available');
            });
        }
    }
};
