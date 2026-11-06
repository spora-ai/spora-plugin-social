<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * The route a target goes out through.
 *
 * `via` is stored rather than derived: the same network on two routes is
 * two accounts, the publisher resolves a channel without a join, and a
 * target survives the connection it was made through being deleted.
 *
 * The copy travels with it. `platform_bodies` is keyed by the channel
 * now, so the keys of the existing rows are rewritten — without that,
 * every post written before this change loses its copy on its next
 * publish.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('social_accounts') && !$schema->hasColumn('social_accounts', 'via')) {
            $schema->table('social_accounts', function (Blueprint $table): void {
                $table->string('via', 32)->default('direct')->after('platform');
            });
        }

        if ($schema->hasTable('social_post_targets') && !$schema->hasColumn('social_post_targets', 'via')) {
            $schema->table('social_post_targets', function (Blueprint $table): void {
                $table->string('via', 32)->default('direct')->after('platform');
            });
        }

        $this->swapTheAccountKey($schema);

        $this->rewriteBodies(true);
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        $this->rewriteBodies(false);

        if ($schema->hasTable('social_accounts') && $schema->hasColumn('social_accounts', 'via')) {
            // The key that names the route goes with the column it names.
            if ($schema->hasIndex('social_accounts', ['principal_id', 'via', 'platform', 'external_id'], 'unique')) {
                $schema->table('social_accounts', function (Blueprint $table): void {
                    $table->dropUnique(['principal_id', 'via', 'platform', 'external_id']);
                });
            }

            $schema->table('social_accounts', function (Blueprint $table): void {
                $table->dropColumn('via');
            });

            $schema->table('social_accounts', function (Blueprint $table): void {
                // The old key cannot hold two accounts on two routes, so
                // a rollback only works while no such pair exists.
                $table->unique(['principal_id', 'platform', 'external_id']);
            });
        }

        if ($schema->hasTable('social_post_targets') && $schema->hasColumn('social_post_targets', 'via')) {
            $schema->table('social_post_targets', function (Blueprint $table): void {
                $table->dropColumn('via');
            });
        }
    }

    /**
     * The account's key: the route joins it.
     *
     * The old key says one account per network, and the same network on
     * two routes is two accounts with two tokens.
     */
    private function swapTheAccountKey(Builder $schema): void
    {
        if (!$schema->hasTable('social_accounts')) {
            return;
        }

        $this->dropTheAccountKey($schema);

        if ($schema->hasIndex('social_accounts', ['principal_id', 'via', 'platform', 'external_id'], 'unique')) {
            return;
        }

        $schema->table('social_accounts', function (Blueprint $table): void {
            // 59 characters — MySQL allows 64 for an index name.
            $table->unique(['principal_id', 'via', 'platform', 'external_id']);
        });
    }

    /**
     * The key that says one account per network.
     */
    private function dropTheAccountKey(Builder $schema): void
    {
        if (!$schema->hasIndex('social_accounts', ['principal_id', 'platform', 'external_id'], 'unique')) {
            return;
        }

        $schema->table('social_accounts', function (Blueprint $table): void {
            $table->dropUnique(['principal_id', 'platform', 'external_id']);
        });
    }

    /**
     * The copy's keys, moved.
     *
     * @param bool $toChannels whether the keys become the channel's names
     */
    private function rewriteBodies(bool $toChannels): void
    {
        if (!Capsule::schema()->hasTable('social_posts')) {
            return;
        }

        $rows = Capsule::table('social_posts')->whereNotNull('platform_bodies')->get();

        foreach ($rows as $row) {
            $bodies = json_decode((string) $row->platform_bodies, true);

            if (!is_array($bodies)) {
                continue;
            }

            $rewritten = $toChannels ? $this->toChannelKeys($bodies) : $this->toNetworkKeys($bodies);

            if ($rewritten === $bodies) {
                continue;
            }

            Capsule::table('social_posts')
                ->where('id', $row->id)
                ->update(['platform_bodies' => json_encode((object) $rewritten)]);
        }
    }

    /**
     * The copy's keys, moved to the channel's names.
     *
     * A key that names a route already is one, and moving it a second
     * time would make "direct:direct:linkedin" — so the rewrite is a
     * no-op the second time it runs.
     *
     * @param array<array-key, mixed> $bodies
     * @return array<array-key, mixed>
     */
    private function toChannelKeys(array $bodies): array
    {
        $rewritten = [];

        foreach ($bodies as $key => $body) {
            if (!is_string($key)) {
                $rewritten[$key] = $body;
                continue;
            }

            $rewritten[str_contains($key, ':') ? $key : 'direct:' . $key] = $body;
        }

        return $rewritten;
    }

    /**
     * The copy's keys, moved back to the network's names.
     *
     * Only the plugin's own route goes back: a key of another route
     * names a network the old schema never had.
     *
     * @param array<array-key, mixed> $bodies
     * @return array<array-key, mixed>
     */
    private function toNetworkKeys(array $bodies): array
    {
        $rewritten = [];

        foreach ($bodies as $key => $body) {
            if (!is_string($key)) {
                $rewritten[$key] = $body;
                continue;
            }

            $rewritten[str_starts_with($key, 'direct:') ? substr($key, strlen('direct:')) : $key] = $body;
        }

        return $rewritten;
    }
};
