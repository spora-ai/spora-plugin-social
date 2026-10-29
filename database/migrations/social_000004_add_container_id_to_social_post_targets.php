<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The container an Instagram publish was prepared in.
 *
 * Instagram creates a container and publishes it in a second call, so a
 * publish that half-finished — the container is there and the publish is
 * not — leaves a container id behind that nobody kept. The container is
 * what the reconciliation asks about: `GET /{container}?fields=status_code`
 * says whether it is still publishing, finished or expired. It expires
 * after 24 hours, so the id is worth exactly that long.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('social_post_targets') && !$schema->hasColumn('social_post_targets', 'container_id')) {
            $schema->table('social_post_targets', function (Blueprint $table): void {
                $table->string('container_id', 255)->nullable()->after('external_url');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('social_post_targets') && $schema->hasColumn('social_post_targets', 'container_id')) {
            $schema->table('social_post_targets', function (Blueprint $table): void {
                $table->dropColumn('container_id');
            });
        }
    }
};
