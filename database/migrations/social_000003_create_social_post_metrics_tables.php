<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One row per target per day, so the time series is a query and not a
 * recomputation.
 *
 * Impressions keep climbing for days after a post goes out and the
 * platforms restate them, so a reading is a dated snapshot and never a
 * final value. Overwriting one row per target would throw that history
 * away and make the chart a lie.
 *
 * The counters are the common set the three APIs are normalised into. Each
 * platform's own numbers are kept beside them in `platform_metrics`, so the
 * UI can label a counter with the platform's own term where the terms
 * differ — and so a change in one platform's vocabulary does not silently
 * rewrite the others'.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('social_post_metrics')) {
            $schema->create('social_post_metrics', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('target_id');
                $table->date('metric_date');
                $table->unsignedBigInteger('reach')->nullable();
                $table->unsignedBigInteger('impressions')->nullable();
                $table->unsignedBigInteger('likes')->nullable();
                $table->unsignedBigInteger('comments')->nullable();
                $table->unsignedBigInteger('shares')->nullable();
                $table->unsignedBigInteger('saves')->nullable();
                $table->unsignedBigInteger('clicks')->nullable();
                $table->json('platform_metrics')->nullable();
                $table->timestamp('measured_at')->nullable();
                $table->timestamps();

                $table->foreign('post_id')->references('id')->on('social_posts')->onDelete('cascade');
                $table->foreign('target_id')->references('id')->on('social_post_targets')->onDelete('cascade');

                // One reading per target per day: the collector overwrites the
                // day's row rather than stacking duplicates.
                $table->unique(['target_id', 'metric_date']);
                $table->index(['post_id', 'metric_date']);
            });
        }
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('social_post_metrics');
    }
};
