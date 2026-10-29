<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The connected accounts and their OAuth tokens.
 *
 * `social_accounts` and `social_account_tokens` are split because the token
 * row is the one that holds secrets: it is written encrypted and never
 * selected into a list response. A row per account is the unit of
 * connection — one Facebook login can carry several pages, but each page
 * publishes under its own Page token, so each page is its own account row.
 *
 * `platform` and `kind` are plain strings rather than enums so a fourth
 * network is an additive migration and not a data rewrite.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('social_accounts')) {
            $schema->create('social_accounts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('principal_id');
                $table->string('platform', 32);
                $table->string('kind', 32);
                $table->string('external_id', 191);
                $table->string('display_name', 255)->nullable();
                $table->string('username', 255)->nullable();
                $table->string('status', 32)->default('active');
                $table->timestamp('token_expires_at')->nullable();
                $table->unsignedBigInteger('followers')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamps();

                $table->foreign('principal_id')->references('id')->on('principals')->onDelete('cascade');

                // One principal connects one account per platform page at most:
                // reconnecting updates the row instead of stacking duplicates.
                $table->unique(['principal_id', 'platform', 'external_id']);
                $table->index(['principal_id', 'platform']);
            });
        }

        if (!$schema->hasTable('social_account_tokens')) {
            $schema->create('social_account_tokens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                // Every token column holds ciphertext produced by core's
                // SecurityManager, never a plaintext token.
                $table->text('access_token');
                $table->text('refresh_token')->nullable();
                $table->text('page_access_token')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('scopes', 1024)->nullable();
                $table->timestamps();

                $table->foreign('account_id')->references('id')->on('social_accounts')->onDelete('cascade');
                $table->unique('account_id');
            });
        }
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('social_account_tokens');
        Capsule::schema()->dropIfExists('social_accounts');
    }
};
