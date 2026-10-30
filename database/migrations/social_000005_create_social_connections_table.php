<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The connection: one login, one token, many accounts.
 *
 * The token moves here from `social_account_tokens`, because under a Meta
 * system-user token one token covers every Instagram account and every Page
 * the client shared — a token per account would be the same token stored
 * several times.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('social_connections')) {
            $schema->create('social_connections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('principal_id');
                $table->string('provider', 32);
                $table->string('business_external_id', 255)->nullable();
                $table->string('config_id', 255)->nullable();
                $table->text('access_token');
                $table->text('refresh_token')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('status', 32)->default('active');
                $table->text('scopes')->nullable();
                $table->timestamps();

                $table->index(['principal_id', 'provider']);
            });
        }
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('social_connections');
    }
};
