<?php
/**
 * Lightweight autoloader for NPPP\
 * Description: Tries classmap; WordPress-style files; falls back to PSR-4; supports APCu path caching.
 * Version: 2.1.3
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

declare(strict_types=1);
if ( ! defined('ABSPATH') ) {
    exit;
}

// Guard against re-registration
if (!isset($GLOBALS['NPPP_AUTOLOADER_REGISTERED'])) {
    $GLOBALS['NPPP_AUTOLOADER_REGISTERED'] = true;

    spl_autoload_register(static function (string $class): bool {
        $prefix  = 'NPPP\\';
        $baseDir = __DIR__ . '/';

        // Namespace filter
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return false;
        }

        // APCu cache
        $useApcu = false;
        if (function_exists('apcu_enabled')) {
            $useApcu = apcu_enabled();
            if ((PHP_SAPI === 'cli') || (defined('WP_CLI') && WP_CLI)) {
                $useApcu = $useApcu && (bool) ini_get('apc.enable_cli');
            }
        } elseif (function_exists('apcu_fetch')) {
            // Fallback heuristic for older APCu
            $useApcu = (bool) ini_get('apc.enabled');
        }
        $cacheKey = 'nppp.autoload.' . $class;

        if ($useApcu) {
            $cached = apcu_fetch($cacheKey);
            if (is_string($cached) && $cached !== '' && is_file($cached) && is_readable($cached)) {
                require $cached;
                return true;
            }
        }

        // Classmap (fast path)
        static $classmap = [
            'NPPP\\Setup'     => 'class-setup.php',
        ];

        if (isset($classmap[$class])) {
            $file = $baseDir . $classmap[$class];
            if (is_file($file) && is_readable($file)) {
                require $file;
                if ($useApcu) apcu_store($cacheKey, $file, 3600);
                return true;
            }
        }

        // Derive the relative class
        $relative = substr($class, strlen($prefix));

        // WordPress-style
        $wpStyle = 'class-' . strtolower(str_replace('\\', '-', $relative)) . '.php';
        $path1   = $baseDir . $wpStyle;
        if (is_file($path1) && is_readable($path1)) {
            require $path1;
            if ($useApcu) apcu_store($cacheKey, $path1, 3600);
            return true;
        }

        // PSR-4 fallback
        $psr4 = str_replace('\\', '/', $relative) . '.php';
        $path2 = $baseDir . $psr4;
        if (is_file($path2) && is_readable($path2)) {
            require $path2;
            if ($useApcu) apcu_store($cacheKey, $path2, 3600);
            return true;
        }

        return false;
    }, false, true);
}
