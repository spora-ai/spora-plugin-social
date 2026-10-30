<?php

declare(strict_types=1);

/*
 * Stand-ins for the two core extension points the social plugin
 * implements.
 *
 * Core ships `Spora\Scheduling\DueWorkProviderInterface` with
 * `Spora\Scheduling\DueWork`, and `Spora\Settings\PluginSettingsProviderInterface`,
 * in the release this plugin targets. The core version the plugin resolves
 * against today predates them, so the analysis and the test suite would
 * stop on an unknown class without these declarations.
 *
 * Each one is guarded and mirrors core's contract exactly: once core ships
 * the real thing the guard skips it and the plugin binds to core's class.
 *
 * @see https://github.com/spora-ai/spora-core — app/Scheduling, app/Settings
 */

namespace Spora\Scheduling {
    if (!interface_exists(DueWorkProviderInterface::class)) {
        interface DueWorkProviderInterface
        {
            /**
             * @param  list<int> $principalIds principals the caller may act for
             * @return list<DueWork>
             */
            public function dueWork(array $principalIds): array;

            /** Execute one claimed unit of work. */
            public function execute(DueWork $work): void;
        }
    }

    if (!class_exists(DueWork::class)) {
        final readonly class DueWork
        {
            /**
             * @param string               $provider    the provider class name — core routes on it
             * @param array<string, mixed> $payload     the provider's own data
             * @param string               $dueAt       `Y-m-d H:i:s` in UTC
             */
            public function __construct(
                public string $provider,
                public int $principalId,
                public array $payload,
                public string $dueAt,
            ) {}
        }
    }
}

namespace Spora\Settings {
    if (!interface_exists(PluginSettingsProviderInterface::class)) {
        interface PluginSettingsProviderInterface
        {
            /** The plugin slug. The setting keys and the DB group are derived from it. */
            public function slug(): string;

            /**
             * The settings this plugin contributes.
             *
             * The key is given WITHOUT the `SPORA_<SLUG>_` prefix — the
             * registry builds the full key.
             *
             * @return list<SettingDefinition>
             */
            public function settings(): array;
        }
    }
}
