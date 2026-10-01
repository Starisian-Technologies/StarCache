# StarCache: Advanced WordPress Caching MU-Plugin

## Introduction

StarCache is a comprehensive WordPress MU-Plugin (Must-Use Plugin) that provides
intelligent, multi-layered caching for PHP/WordPress applications. It
auto-detects the best available cache backend, hooks into WordPress at the
right moments, and gives developers a clean API to improve site performance
without manual plumbing.

## Features

| Feature | Details |
|---|---|
| **Backend auto-detection** | Probes Redis → Memcached → Memcache → WordPress built-in object cache in priority order at startup. |
| **OPcache awareness** | Detects whether PHP OPcache is enabled and exposes the status via API and the admin bar. |
| **Full-page cache** | Output-buffer caching for entire HTML responses; serves cached pages before WordPress queries the database. |
| **Fragment / partial cache** | `getFragment` / `saveFragment` helpers to cache chunks of template output (sidebar, navigation, widgets, etc.). |
| **Varnish integration** | Sends `Cache-Control`, `Vary`, and `X-Cache-Tags` headers; issues HTTP `PURGE` requests to Varnish on post save / status change. |
| **Query cache utility (deprecated)** | `StarQueryCache` provides `cachedWpdbQuery()` for SQL look-aside caching. Hook-based `WP_Query` caching via `posts_pre_query` / `the_posts` is not registered and will not be in v3.0. |
| **Transient helpers** | Per-site and **network-wide** (multisite) transient wrappers with consistent key generation. |
| **Asset minification** | Minifies enqueued CSS and JS files in PHP (no external tools), writes to a filesystem cache, and swaps the enqueued `src` URL. |
| **Multisite-aware** | Every cache key embeds the current blog ID; network transients span all sites. |
| **WP-CLI command** | `wp starcache flush` bumps version counters to invalidate cache entries. It does not delete underlying storage. |
| **Admin bar badge** | Shows the active backend (e.g. `StarCache: REDIS + OPcache`) to admins. |

---

## Installation (MU-Plugin)

1. Download the release package and extract it directly into `wp-content/mu-plugins/`.
2. Confirm that `wp-content/mu-plugins/starcache.php` is beside the extracted
   `wp-content/mu-plugins/starcache/` directory; do not move files out of that directory.
3. The plugin then loads automatically as an MU-plugin.
4. If you are using Composer, add the package:

```bash
composer require maximilliangroupinc/starcache
```

Then in your MU-Plugin loader file:

```php
require_once WP_CONTENT_DIR . '/mu-plugins/starcache.php';
```

---

## Optional Configuration (wp-config.php)

```php
// Redis
define('WP_REDIS_HOST',     '127.0.0.1');
define('WP_REDIS_PORT',     6379);
define('WP_REDIS_PASSWORD', 'secret');   // optional
define('WP_REDIS_DATABASE', 0);          // optional

// Memcached (preferred over Memcache when both extensions are loaded)
define('MEMCACHED_SERVERS', [
    ['host' => '127.0.0.1', 'port' => 11211],
]);

// Varnish (enables Cache-Control headers and PURGE requests)
define('VARNISH_HOST', '127.0.0.1');
define('VARNISH_PORT', 6081);

// Asset minification cache directory (must be web-accessible)
define('STARCACHE_ASSET_DIR', WP_CONTENT_DIR . '/cache/starcache/assets');
define('STARCACHE_ASSET_URL', WP_CONTENT_URL . '/cache/starcache/assets');

// Set to false to disable asset minification
define('STARCACHE_MINIFY', true);
```

---

## Usage

### Programmatic data caching

```php
// Get the singleton StarCache instance
$cache = star_cache();

// Store a value (TTL defaults to 1 hour)
$cache->star_setCachedData(['user' => 'Jane'], 'user_profile', '42');

// Retrieve
$data = $cache->star_getCachedData('user_profile', '42');

// Delete
$cache->star_deleteCachedData('user_profile', '42');

// Cache-aside (get-or-set pattern)
// Uses lock-based soft-TTL stale protection (not full background SWR).
$posts = $cache->star_remember('homepage_posts', function () {
    return get_posts(['numberposts' => 10]);
}, 300);
```

### Global helper functions (no namespace required)

```php
star_cache_set(['key' => 'value'], 'my_feature');          // store
star_cache_get('my_feature');                              // retrieve
star_cache_delete('my_feature');                           // delete
star_cache_remember('nav_menu', fn() => wp_nav_menu([
    'echo' => false,
]), 3600);
```

### Fragment / partial caching

```php
if (!\StarCache\StarPageCache::getFragment('sidebar')) {
    // Render sidebar – output is captured
    get_sidebar();
    \StarCache\StarPageCache::saveFragment('sidebar');
}
```

### Raw SQL query caching

```php
global $wpdb;
$sql     = $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_status = %s", 'publish');
$results = \StarCache\StarQueryCache::cachedWpdbQuery($sql, ARRAY_A, 300);
```

### Network-wide transients (multisite)

```php
// Store data shared across all sites in the network
\StarCache\StarTransientCache::star_setNetworkCachedData($config, 'global_settings');

// Retrieve
$config = \StarCache\StarTransientCache::star_getNetworkCachedData('global_settings');
```

### Varnish purge

```php
// Purge a specific URL from Varnish + object cache
\StarCache\StarPageCache::purgeUrl('https://example.com/my-page/');
```

### WP-CLI

```bash
wp starcache flush
```

---

## Cache backend priority

StarCache probes backends in the following order and uses the first one that
responds successfully:

1. **Redis** – tries PhpRedis (`ext-redis`) first, then Predis (`\Predis\Client`) using `WP_REDIS_*` constants.
2. **Memcached** – requires the `memcached` PHP extension; reads `MEMCACHED_SERVERS`.
3. **Memcache** – requires the `memcache` PHP extension; reads `MEMCACHE_SERVER_HOST/PORT`.
4. **WordPress object cache** – always available; backed by APCu, file, or database
   depending on the active object-cache drop-in.

The detected backend is exposed via `StarCacheAdapter::getBackend()` and shown in
the WordPress admin bar for administrators.

`StarCacheAdapter::getBackendCapabilities()` provides runtime capability detection for:

- PhpRedis availability
- Predis availability
- Memcached availability
- Memcache availability
- WordPress object-cache add/flush support
- WordPress object-cache persistence mode (`persistent` vs `runtime`)

### Dangerous flush guard

Global backend flush operations are blocked by default and intended only for development/testing.
In production, prefer version bump invalidation and edge purge flows. To allow a backend flush explicitly:

```php
define('STARCACHE_ALLOW_DANGEROUS_FLUSH', true);
```

Without this constant, `StarCacheAdapter::flush()` returns `false` and logs a warning.
Treat `StarCacheAdapter::flush()` as local dev/test tooling only. In production,
invalidate via version bumping plus edge/cache-layer purge flows instead of backend flushes.

---

## Filters and hooks

| Filter / Action | Purpose |
|---|---|
| `starcache_bypass_page_cache` | Return `true` to skip page caching for the current request. |
| `starcache_page_ttl` | Override the full-page cache TTL (default `600` seconds). |
| `starcache_query_ttl` | Override the WP_Query cache TTL (default `300` seconds). |
| `starcache_cache_query` | Return `false` to prevent a specific `WP_Query` from being cached. |
| `starcache_varnish_enabled` | Return `true` to force-enable Varnish headers without defining `VARNISH_HOST`. |
| `starcache_minify_enabled` | Return `false` to disable asset minification at runtime. |
| `starcache_after_purge` | Fired after a page is purged; receives `$postId` and `$post`. |
| `starcache_after_query_invalidate` | Fired after query caches are invalidated; receives `$postId`. |

---

## Running Tests

```bash
composer install
./vendor/bin/phpunit
```

---

## Lifecycle behavior

- **Activation (regular plugin):** no schema or data mutation.
- **Deactivation (regular plugin):** clears pending `starcache_build_asset` cron events and generated asset-cache files for the current site, or for every site during network deactivation.
- **Uninstall:** keeps user data by default and removes only StarCache-generated asset-cache files plus pending StarCache cron events, including multisite network cleanup when supported by WordPress.

---

## License

StarCache is released under the [Apache 2.0 License](LICENSE).

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](contributing.md) for details.

## Support

If you encounter any issues or have questions, please open an issue on the
[GitHub repository](https://github.com/MaximillianGroupInc/StarCache).
