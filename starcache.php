<?php

/**
 * Plugin Name:  StarCache
 * Plugin URI:   https://github.com/MaximillianGroupInc/StarCache
 * Description:  Deterministic cache orchestration engine for WordPress. Auto-detects Redis,
 *               Memcached, Memcache, and OPcache; provides context-aware full-page caching,
 *               fragment caching, Varnish integration, version-based invalidation, and
 *               CSS/JS minification. Multisite-aware.
 * Version:      2.1.1
 * Author:       MaximillianGroup (Max Barrett)
 * Author URI:   https://github.com/MaximillianGroupInc
 * License:      Apache 2.0
 * Network:      true
 *
 * ---
 * INSTALLATION
 * ---
 * Extract the release archive into wp-content/mu-plugins/. The loader remains
 * at the MU-plugin root and its companion classes remain in starcache/.
 * As an MU-Plugin it is loaded automatically on every WordPress request – no
 * activation step is required.  It is also safe to load via Composer autoload:
 *
 *   require_once WP_CONTENT_DIR . '/mu-plugins/starcache.php';
 *
 * ---
 * OPTIONAL CONFIGURATION  (add to wp-config.php)
 * ---
 *   define('WP_REDIS_HOST',        '127.0.0.1');
 *   define('WP_REDIS_PORT',        6379);
 *   define('WP_REDIS_PASSWORD',    'secret');
 *   define('WP_REDIS_DATABASE',    0);
 *
 *   define('MEMCACHED_SERVERS', [['host' => '127.0.0.1', 'port' => 11211]]);
 *
 *   define('VARNISH_HOST', '127.0.0.1');   // enables Varnish PURGE requests
 *   define('VARNISH_PORT', 6081);
 *
 *   define('STARCACHE_ASSET_DIR', '/var/www/html/wp-content/cache/starcache/assets');
 *   define('STARCACHE_ASSET_URL', 'https://example.com/wp-content/cache/starcache/assets');
 *   define('STARCACHE_MINIFY', false);     // set to false to disable asset minification
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.1
 * @license Apache 2.0
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// StarCache engine — all orchestration code lives in the StarCache namespace.
// The file uses braced namespace blocks so that the star_cache_* helper
// functions can be declared in the GLOBAL namespace, making function_exists()
// guards correct and preventing fatal redeclare errors on double-load
// (e.g. loaded as MU-Plugin AND via Composer autoload).
// ---------------------------------------------------------------------------

namespace StarCache {

    if (!defined('ABSPATH')) {
        exit;
    }

    // -------------------------------------------------------------------------
    // Load class files
    // (When installed via Composer the autoloader already handles this.)
    // -------------------------------------------------------------------------
    /**
     * Candidate directories for companion classes. The first layout is used
     * by release archives; the second supports Composer and legacy flat installs.
     *
     * @var list<string> $_starCacheClassDirectories
     */
    $_starCacheClassDirectories = [__DIR__ . '/starcache', __DIR__];

    /** @var string|null $_starCacheClassDirectory Resolved companion-class directory. */
    $_starCacheClassDirectory = null;

    /** @var string $_starCacheCandidateDirectory Candidate currently being inspected. */
    foreach ($_starCacheClassDirectories as $_starCacheCandidateDirectory) {
        if (is_file($_starCacheCandidateDirectory . '/StarCacheKey.php')) {
            $_starCacheClassDirectory = $_starCacheCandidateDirectory;
            break;
        }
    }

    if ($_starCacheClassDirectory === null) {
        throw new \RuntimeException(
            'StarCache installation is incomplete: StarCacheKey.php was not found '
            . 'beside the loader or in the starcache companion directory.'
        );
    }

    /** @var list<string> $_starCacheClasses Class basenames loaded in dependency order. */
    $_starCacheClasses = [
        'StarCacheKey',
        'StarCacheAdapter',
        'StarCacheContext',
        'StarVersionStore',
        'StarResponseController',
        'StarCache',
        'StarTransientCache',
        'StarPageCache',
        'StarQueryCache',    // Deprecated — removal target for v3.0
        'StarAssetMinifier',
        'StarPluginLifecycle',
    ];

    /** @var string $_starCacheClass Class basename currently being loaded. */
    foreach ($_starCacheClasses as $_starCacheClass) {
        if (!class_exists(__NAMESPACE__ . '\\' . $_starCacheClass)) {
            /** Absolute path to the companion class file currently being loaded. */
            $_starCacheClassFile = $_starCacheClassDirectory . '/' . $_starCacheClass . '.php';
            if (!is_file($_starCacheClassFile)) {
                throw new \RuntimeException(
                    'StarCache installation is incomplete: missing companion class file '
                    . $_starCacheClass . '.php.'
                );
            }
            require_once $_starCacheClassFile;
        }
    }
    unset(
        $_starCacheCandidateDirectory,
        $_starCacheClassDirectories,
        $_starCacheClassDirectory,
        $_starCacheClassFile,
        $_starCacheClass,
        $_starCacheClasses
    );

    // -------------------------------------------------------------------------
    // REQUEST LIFECYCLE — hook ordering is everything in WordPress.
    //
    //   plugins_loaded  0  → resolve context dimensions  (BEFORE any cache lookup)
    //   plugins_loaded  1  → initialise adapter
    //   init            1  → start page-cache output buffering
    //   send_headers    1  → lock context + apply response headers
    //   save_post / ... → invalidation via version bumps
    // -------------------------------------------------------------------------

    // Step 1: Resolve context dimensions FIRST, before any cache key is built.
    add_action('plugins_loaded', [StarCacheContext::class, 'resolve'], 0);

    // Step 2: Initialise the cache adapter.
    add_action('plugins_loaded', [StarCacheAdapter::class, 'init'], 1);

    // Step 3: Start page-cache buffering (after context is resolved, before content).
    add_action('init', [StarPageCache::class, 'startPageCache'], 1);

    // Step 4: Lock context and apply cache-control headers just before output.
    //   Priority 999 means this runs AFTER all default-priority (10) plugin
    //   callbacks on 'send_headers', so the upstream-header gate in
    //   StarResponseController::apply() can reliably detect headers set by
    //   WooCommerce, REST API, and other plugins.
    add_action('send_headers', static function (): void {
        StarCacheContext::lock();
        StarResponseController::apply();
    }, 999);

    // -------------------------------------------------------------------------
    // Cache invalidation — version bumps, not direct deletion
    // -------------------------------------------------------------------------

    // Bump GROUP_PAGES + GROUP_OBJECTS + Varnish PURGE on post save / status change
    add_action('save_post', [StarPageCache::class, 'purgeOnSave'], 10, 2);
    add_action('transition_post_status', [StarPageCache::class, 'purgeOnStatusChange'], 10, 3);

    // Also invalidate on trash / permanent delete
    add_action('trashed_post', static function (int $postId): void {
        $post = function_exists('get_post') ? get_post($postId) : null;
        if ($post instanceof \WP_Post) {
            StarPageCache::purgeOnSave($postId, $post);
        }
    });
    add_action('before_delete_post', static function (int $postId): void {
        $post = function_exists('get_post') ? get_post($postId) : null;
        if ($post instanceof \WP_Post) {
            StarPageCache::purgeOnSave($postId, $post);
        }
    });

    // Bump GROUP_QUERIES when post cache is cleaned (covers term / meta updates).
    // Note: StarQueryCache filter hooks (posts_pre_query / the_posts) are intentionally
    // NOT registered here. Use star_cache_remember() for query caching instead.
    add_action('clean_post_cache', static function (int $postId): void {
        StarVersionStore::bump(StarVersionStore::GROUP_QUERIES);
    });

    // Bump GROUP_OBJECTS when any post meta value is updated.
    // This covers custom fields that affect rendered output or query results.
    add_action('updated_post_meta', static function (int $metaId, int $postId): void {
        StarVersionStore::bump(StarVersionStore::GROUP_OBJECTS);
    }, 10, 2);

    // Bump GROUP_PAGES + GROUP_QUERIES when taxonomy terms are assigned.
    // Term changes affect archive/taxonomy pages and any query using tax_query.
    add_action('set_object_terms', static function (int $objectId): void {
        StarVersionStore::bump(StarVersionStore::GROUP_PAGES);
        StarVersionStore::bump(StarVersionStore::GROUP_QUERIES);
    });

    // Bump GROUP_OBJECTS when any option is updated.
    // Option changes (e.g. site title, theme settings) can affect cached output.
    add_action('updated_option', static function (string $option): void {
        StarVersionStore::bump(StarVersionStore::GROUP_OBJECTS);
    });

    // -------------------------------------------------------------------------
    // Asset minification (StarAssetMinifier — extraction to companion plugin planned)
    // -------------------------------------------------------------------------
    add_action('init', [StarAssetMinifier::class, 'init'], 5);
    add_action('wp_print_styles', [StarAssetMinifier::class, 'processStyles'], 5);
    add_action('wp_print_scripts', [StarAssetMinifier::class, 'processScripts'], 5);
    add_action(StarAssetMinifier::CRON_HOOK, [StarAssetMinifier::class, 'buildAssetFromCron'], 10, 3);

    add_action('upgrader_process_complete', [StarAssetMinifier::class, 'flushAssets']);
    add_action('switch_theme', [StarAssetMinifier::class, 'flushAssets']);

    // -------------------------------------------------------------------------
    // Admin bar integration
    // -------------------------------------------------------------------------
    add_action('admin_bar_menu', static function (\WP_Admin_Bar $bar): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $backend = StarCacheAdapter::getBackend();
        $opcache = StarCacheAdapter::isOpcacheEnabled() ? ' + OPcache' : '';
        $label   = 'StarCache: ' . strtoupper($backend) . $opcache;

        $bar->add_menu([
            'id'    => 'starcache',
            'title' => esc_html($label),
            'href'  => admin_url('tools.php?page=starcache'),
            'meta'  => ['title' => __('StarCache – Active cache backend', 'starcache')],
        ]);
    }, 100);

    // -------------------------------------------------------------------------
    // WP-CLI support
    // -------------------------------------------------------------------------
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('starcache flush', static function (): void {
            // Version bumps only — NOT a global cache flush (no thundering herd).
            StarVersionStore::bumpAll();
            StarAssetMinifier::flushAssets();
            \WP_CLI::success('StarCache flushed (version bumped).');
        });

        \WP_CLI::add_command('starcache status', static function (): void {
            $backend = StarCacheAdapter::getBackend();
            $opcache = StarCacheAdapter::isOpcacheEnabled() ? 'enabled' : 'disabled';
            $context = function_exists('wp_json_encode')
                ? wp_json_encode(StarCacheContext::all())
                : json_encode(StarCacheContext::all());
            \WP_CLI::line('Backend : ' . $backend);
            \WP_CLI::line('OPcache : ' . $opcache);
            \WP_CLI::line('Context : ' . ($context !== false ? $context : '{}'));
        });
    }
}

// ---------------------------------------------------------------------------
// Public helper functions — declared in the GLOBAL namespace so that:
//   (a) function_exists('star_cache') returns true when already loaded
//   (b) loading via both MU-Plugin path and Composer autoload never causes
//       a fatal "Cannot redeclare" error
// ---------------------------------------------------------------------------

namespace {

    if (!function_exists('star_cache')) {
        /**
         * Return the singleton StarCache instance.
         */
        function star_cache(): \StarCache\StarCache
        {
            static $instance = null;
            if ($instance === null) {
                $instance = new \StarCache\StarCache();
            }
            return $instance;
        }
    }

    if (!function_exists('star_cache_get')) {
        /**
         * Retrieve a cached value.
         *
         * @return mixed|false
         */
        function star_cache_get(string $reference, ?string $userId = null): mixed
        {
            return star_cache()->star_getCachedData($reference, $userId);
        }
    }

    if (!function_exists('star_cache_set')) {
        /**
         * Store a value in cache.
         */
        function star_cache_set(mixed $data, string $reference, int $ttl = 0, ?string $userId = null): bool
        {
            if ($ttl > 0) {
                return star_cache()->star_setCachedDataWithTtl($data, $reference, $ttl, $userId);
            }
            return star_cache()->star_setCachedData($data, $reference, $userId);
        }
    }

    if (!function_exists('star_cache_delete')) {
        /**
         * Delete a cached value.
         */
        function star_cache_delete(string $reference, ?string $userId = null): bool
        {
            return star_cache()->star_deleteCachedData($reference, $userId);
        }
    }

    if (!function_exists('star_cache_remember')) {
        /**
         * Get-or-set cache (cache-aside pattern).
         *
         * Exceptions thrown by $callback propagate to the caller unchanged.
         * No cache entry is written when $callback throws.
         *
         * @return mixed
         */
        function star_cache_remember(
            string $reference,
            callable $callback,
            int $ttl = 3600,
            ?string $userId = null
        ): mixed {
            return star_cache()->star_remember($reference, $callback, $ttl, $userId);
        }
    }

    if (function_exists('register_deactivation_hook')) {
        register_deactivation_hook(__FILE__, static function (): void {
            \StarCache\StarPluginLifecycle::deactivate();
        });
    }
}
