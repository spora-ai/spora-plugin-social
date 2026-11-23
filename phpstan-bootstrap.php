<?php

declare(strict_types=1);

use Composer\InstalledVersions;

/*
 * Bootstrap for PHPStan: the constants and Laravel helpers that are
 * normally set at runtime by the host (public/index.php) but are not
 * available during static analysis.
 *
 * The path helpers keep Laravel's names: larastan calls `config_path()`
 * and the others while it analyses, so the names it knows have to exist.
 * A camelCase spelling of them breaks the analysis outright, and the
 * spelling is Laravel's, not the plugin's.
 *
 * The last include is the shim for the two core extension points the
 * plugin implements; see `compat/core-extension-points.php`.
 */

define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/vendor/autoload.php';

// Larastan reads the framework version from the global constant. The reads
// in `Larastan\Larastan\Methods` name `LARAVEL_VERSION` unqualified, and PHP
// resolves an unqualified constant against the current namespace and then
// the global one — never an intermediate parent — so they never see a
// `Larastan\Larastan\LARAVEL_VERSION`, while the `defined('LARAVEL_VERSION')`
// guard sees the global name only.
//
// The value is the installed one, not a literal: Larastan gates behaviour on
// it and selects its stub directories with
// `version_compare($directory, LARAVEL_VERSION, '<=')`, so a pinned version
// analyses the wrong framework. Composer reports four segments
// (`13.39.0.0`); Larastan's thresholds and its stub directory names are
// three, and `version_compare` does not zero-pad, so a trailing `.0` is
// dropped.
if (!defined('LARAVEL_VERSION') && InstalledVersions::isInstalled('illuminate/database')) {
    define('LARAVEL_VERSION', (string) preg_replace(
        '/^(\d+\.\d+\.\d+)\.0$/',
        '$1',
        InstalledVersions::getVersion('illuminate/database'),
    ));
}

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string // NOSONAR php:S100 — Laravel convention: snake_case path helper
    {
        return BASE_PATH . '/config' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string // NOSONAR php:S100 — Laravel convention: snake_case path helper
    {
        return BASE_PATH . '/app' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string // NOSONAR php:S100 — Laravel convention: snake_case path helper
    {
        return BASE_PATH . '/storage' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

if (!function_exists('database_path')) {
    function database_path(string $path = ''): string // NOSONAR php:S100 — Laravel convention: snake_case path helper
    {
        return BASE_PATH . '/database' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

require_once BASE_PATH . '/compat/core-extension-points.php';
