<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The posts, the per-account outcome of a post, and the attachments.
 *
 * `social_post_targets` exists because a post can succeed on one network
 * and fail on another, and a post-level status cannot say that. The post's
 * own `status` is derived from these rows, never written independently.
 *
 * `platform_bodies` holds the per-platform copy as a JSON object keyed by
 * platform: the draft that reads well on LinkedIn is not the one that reads
 * well on Instagram, and a single `body` column would force a choice.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('social_posts')) {
            $schema->create('social_posts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('principal_id');
                $table->string('title', 255)->nullable();
                $table->text('body')->nullable();
                $table->json('platform_bodies')->nullable();
                $table->string('status', 32)->default('draft');
                $table->string('source', 32)->default('manual');
                $table->timestamp('scheduled_for')->nullable();
                $table->string('timezone', 64)->default('UTC');
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->foreign('principal_id')->references('id')->on('principals')->onDelete('cascade');

                $table->index(['principal_id', 'status']);
                $table->index('scheduled_for');
            });
        }

        if (!$schema->hasTable('social_post_targets')) {
            $schema->create('social_post_targets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('account_id');
                $table->string('platform', 32);
                $table->string('status', 32)->default('pending');
                $table->string('external_id', 255)->nullable();
                $table->string('external_url', 1024)->nullable();
                $table->text('error')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->foreign('post_id')->references('id')->on('social_posts')->onDelete('cascade');
                $table->foreign('account_id')->references('id')->on('social_accounts')->onDelete('cascade');

                // A post publishes to each account once.
                $table->unique(['post_id', 'account_id']);
                $table->index(['post_id', 'status']);
            });
        }

        if (!$schema->hasTable('social_post_media')) {
            $schema->create('social_post_media', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('post_id');
                $table->string('media_asset_id', 64);
                $table->unsignedInteger('position')->default(0);
                // The derivative format the attachment was prepared in, when the
                // platform needs one — Instagram takes JPEG, so a PNG attachment
                // records the JPEG derivative it was converted into.
                $table->string('derivative_format', 32)->nullable();
                $table->timestamps();

                $table->foreign('post_id')->references('id')->on('social_posts')->onDelete('cascade');

                $table->unique(['post_id', 'media_asset_id']);
            });
        }
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('social_post_media');
        Capsule::schema()->dropIfExists('social_post_targets');
        Capsule::schema()->dropIfExists('social_posts');
    }
};
