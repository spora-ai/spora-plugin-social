<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The key the aggregator's publish goes out under.
 *
 * One key per publish attempt, written to every target of the group
 * before the call. That is what makes a crash survivable: the targets
 * that are still `publishing` carry the key that was used, and the
 * replay uses it again — so the aggregator answers with the original
 * post instead of publishing a second one.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('social_post_targets') && !$schema->hasColumn('social_post_targets', 'idempotency_key')) {
            $schema->table('social_post_targets', function (Blueprint $table): void {
                $table->string('idempotency_key', 64)->nullable()->after('container_id');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('social_post_targets') && $schema->hasColumn('social_post_targets', 'idempotency_key')) {
            $schema->table('social_post_targets', function (Blueprint $table): void {
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
