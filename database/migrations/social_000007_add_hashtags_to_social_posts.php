<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The hashtags a post carries.
 *
 * They are a field of the post and not a part of its body: the same tags
 * go to every platform, and the publisher appends them to whichever body
 * that platform gets.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('social_posts') && !$schema->hasColumn('social_posts', 'hashtags')) {
            $schema->table('social_posts', function (Blueprint $table): void {
                $table->json('hashtags')->nullable()->after('platform_bodies');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('social_posts') && $schema->hasColumn('social_posts', 'hashtags')) {
            $schema->table('social_posts', function (Blueprint $table): void {
                $table->dropColumn('hashtags');
            });
        }
    }
};
