<?php

declare(strict_types=1);

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

if (!defined('LARAVEL_VERSION')) {
    define('LARAVEL_VERSION', '13.0.0');
}

if (!defined('Larastan\Larastan\LARAVEL_VERSION')) {
    define('Larastan\Larastan\LARAVEL_VERSION', LARAVEL_VERSION);
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
