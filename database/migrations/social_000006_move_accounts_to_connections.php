<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The accounts hang off a connection, and the token table goes away.
 *
 * The token is not carried over: a Meta token belongs to a business
 * portfolio, and the old rows carry no portfolio — the operator's accounts
 * have to be connected again. The plugin has never run against a real API,
 * so there is nothing to preserve.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('social_accounts') && !$schema->hasColumn('social_accounts', 'connection_id')) {
            $schema->table('social_accounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('connection_id')->nullable()->after('principal_id');

                // The accounts belong to the connection: removing the
                // login removes what it published as.
                $table->foreign('connection_id')
                    ->references('id')
                    ->on('social_connections')
                    ->onDelete('cascade');
            });
        }

        // The expiry belongs to the connection now: one login, one expiry.
        if ($schema->hasTable('social_accounts') && $schema->hasColumn('social_accounts', 'token_expires_at')) {
            $schema->table('social_accounts', function (Blueprint $table): void {
                $table->dropColumn('token_expires_at');
            });
        }

        $schema->dropIfExists('social_account_tokens');
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('social_account_tokens')) {
            $schema->create('social_account_tokens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->text('access_token');
                $table->text('refresh_token')->nullable();
                $table->text('page_access_token')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->text('scopes')->nullable();
                $table->timestamps();

                $table->foreign('account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            });
        }
    }
};
