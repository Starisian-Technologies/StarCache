StarCache --- Technical Specification
===================================

sparxstar-starcache (Starisian Technologies)
============================================

Version 2.1.1 | Starisian Technologies | Apache 2.0

* * * * *

What StarCache is
-----------------

StarCache is a deterministic cache orchestration engine for WordPress, deployed as a Must-Use (MU) plugin. It is not a caching layer. It is an orchestrator that coordinates context resolution, backend selection, key construction, response control, and invalidation into a single disciplined system.

The distinction matters. A caching layer stores and retrieves data. An orchestration engine ensures that every cache decision --- what to cache, under what key, with what headers, invalidated by what event --- is made deterministically, in the correct order, with clean boundaries between concerns.

StarCache operates in a hostile commercial WordPress ecosystem alongside arbitrary third-party plugins. Its guarantees must hold regardless of what other plugins do. No cache decision depends on plugin cooperation.

The MU-plugin release archive places `starcache.php` at the archive root and
the companion class files in the adjacent `starcache/` directory. The loader
also supports legacy flat and Composer layouts where the classes are beside
the loader, and reports incomplete installations without exposing absolute
server paths.

* * * * *

Position in the platform
------------------------

StarCache is a Starisian Technologies product. It operates independently of the SPARXSTAR sovereign knowledge platform but shares the same architectural standards:

-   PHP 8.2+
-   `declare(strict_types=1)` in every file
-   PSR-12 coding standards
-   PHPStan Level 5
-   No global functions except WordPress integration points
-   Hostile plugin environment assumption --- guarantees enforced by architecture

StarCache does not interact with the Dheghom DAL, Helios, Sirus, Mehns, or any SPARXSTAR component. It is a general-purpose WordPress caching system that happens to be built to the same engineering standards.

* * * * *

The request lifecycle
---------------------

This is the most important section. Hook ordering is everything in WordPress. StarCache gets this right. Do not change it.

```
plugins_loaded (priority 0)
    → StarCacheContext::resolve()
      Context dimensions resolved FIRST, before any cache lookup.
      Nothing else runs until context is known.

plugins_loaded (priority 1)
    → StarCacheAdapter::init()
      Backend detection runs AFTER context is resolved.
      Redis / Memcached / WP object cache detected and connected.

init (priority 1)
    → StarPageCache::startPageCache()
      Output buffering begins AFTER context and adapter are ready.
      Context is already locked into the key at this point.

send_headers (priority 999, late)
    → StarCacheContext::lock()
    → StarResponseController::apply()
      Context locked --- no further dimension changes accepted.
      Cache-Control policy evaluated after upstream plugins/themes set theirs.

save_post / transition_post_status
    → StarPageCache::purgeOnSave()
    → StarPageCache::purgeOnStatusChange()
      Version bump + Varnish PURGE on content change.

trashed_post / before_delete_post
    → StarPageCache::purgeOnSave() (via post object)

clean_post_cache
    → StarVersionStore::bump(GROUP_QUERIES)
      Query cache invalidated when post cache is cleaned.
      Covers term and meta updates that do not fire save_post.

```

**Rule:** Nothing modifies context dimensions after `plugins_loaded`. If any code modifies context after `plugins_loaded`, cache keys become non-deterministic. Verify this on every PR that touches context.

* * * * *

Component architecture
----------------------

Seven core components with hard boundaries. Each component owns exactly one concern. No component reaches into another's domain.

```
StarCacheContext       --- context dimensions (what this request is)
StarCacheAdapter       --- backend storage (get/set/delete only)
StarCacheKey           --- key construction (how to name a cache entry)
StarVersionStore       --- invalidation (version bumps by group)
StarPageCache          --- full-page cache (buffering + storage only)
StarResponseController --- HTTP response headers ONLY
StarCache              --- public API facade (cache-aside pattern)

```

Two additional components with defined future status:

```
StarTransientCache     --- transient optimization (stable, active)
StarAssetMinifier      --- CSS/JS minification (EXTRACTION REQUIRED --- see below)

```

* * * * *

StarCacheContext --- Context Engine
---------------------------------

**Responsibility:** Resolve and hold the dimensions that determine how a request is cached. Produce a stable hash for cache key construction.

**Three built-in dimensions resolved on every request:**

```
device     --- 'mobile' | 'tablet' | 'desktop'  (User-Agent derived)
auth       --- 'authenticated' | 'anonymous'     (is_user_logged_in())
experiment --- [a-z0-9_] string                  (cookie-derived, sanitized)

```

**Lifecycle:**

```
resolve()  --- called at plugins_loaded priority 0. Idempotent.
set()      --- adds or overrides a dimension. No-op after lock().
lock()     --- called at send_headers priority 999. Freezes dimensions.
hash()     --- SHA-256 of all non-auth dimensions, ksort for determinism.
shouldBypass() --- returns true when auth = 'authenticated'.
reset()    --- test use only. Clears all state.

```

**The auth dimension is excluded from the hash.** Authenticated requests bypass all caches entirely --- their context hash is never used in a key. This is correct behavior. Authenticated users must never receive cached responses from the anonymous cache.

**Custom dimensions via filter:**

```
apply_filters('starcache_context_dimensions', self::$dimensions)

```

### Context dimension constraints (HARDENING REQUIRED)

The `starcache_context_dimensions` filter currently allows unlimited dimensions and unlimited values. This is a cache explosion risk --- every unique combination of dimension values produces a distinct cache entry.

**Required constraints before production:**

```
// Maximum dimension count
const MAX_DIMENSIONS = 7;

// Maximum value length per dimension
const MAX_DIMENSION_VALUE_LENGTH = 64;

// Validation: reject unknown dimensions or oversized values
// after apply_filters runs
if (count(self::$dimensions) > self::MAX_DIMENSIONS) {
    // Log and trim to MAX_DIMENSIONS
}
foreach (self::$dimensions as $name => $value) {
    if (strlen($value) > self::MAX_DIMENSION_VALUE_LENGTH) {
        self::$dimensions[$name] = substr($value, 0, self::MAX_DIMENSION_VALUE_LENGTH);
    }
}

```

* * * * *

StarCacheAdapter --- Backend Storage
----------------------------------

**Responsibility:** Backend detection and raw storage operations. Nothing else.

**Detected backends in priority order:**

```
1\. Redis      (via Predis or PhpRedis --- WP_REDIS_HOST constant)
2. Memcached  (MEMCACHED_SERVERS constant)
3. Memcache   (legacy)
4. WordPress object cache (persistent --- Redis/Memcached via drop-in)
5. WordPress object cache (non-persistent --- in-memory fallback)

```

**The adapter contract --- four methods only:**

```
public static function get(string $key, string $group = ''): mixed;
public static function set(string $key, mixed $data, string $group = '', int $ttl = 0): bool;
public static function delete(string $key, string $group = ''): bool;
public static function getBackend(): string;

```

### Adapter discipline (HARDENING REQUIRED)

The adapter must not build keys. The adapter must not make TTL decisions. The adapter must not evaluate context. If any of these currently happen inside `StarCacheAdapter`, they must be moved to the correct component:

-   Key building → `StarCacheKey`
-   TTL decisions → the calling component (`StarPageCache`, `StarCache`, etc.)
-   Context evaluation → `StarCacheContext`

**Rule:** The adapter is a storage primitive. It receives a finished key and stores or retrieves data. It has no opinion about what the key means.

* * * * *

StarCacheKey --- Key Builder
--------------------------

**Responsibility:** Construct deterministic, collision-resistant cache keys.

**Key components:**

```
namespace     --- 'starcache' prefix
blog_id       --- from get_current_blog_id() --- multisite isolation
user          --- hashed user context (anonymous or user-scoped)
reference     --- caller-supplied identifier (< 250 chars enforced)
context_hash  --- from StarCacheContext::hash()
version       --- from StarVersionStore for the relevant group

```

**Final key:** SHA-256 of the concatenated raw key string.

**Why SHA-256 for the final key:** Raw keys can be arbitrarily long (especially with context hashes and version strings). Backend key length limits (Redis: 512MB, Memcached: 250 bytes for keys) make raw key storage risky. SHA-256 produces a fixed 64-character key regardless of input length.

### Key builder hardening (REQUIRED)

**Reference length guard:**

```
const MAX_REFERENCE_LENGTH = 250;

if (strlen($reference) > self::MAX_REFERENCE_LENGTH) {
    throw new \InvalidArgumentException(
        sprintf(
            'StarCache reference exceeds maximum length of %d characters.',
            self::MAX_REFERENCE_LENGTH
        )
    );
}

```

**Responsibility separation (REQUIRED):**

The key builder currently assembles all components inline. This must be separated into distinct methods:

```
// Correct structure
public static function build(string $reference, ?string $userId = null): string
{
    return hash('sha256', implode('|', [
        self::namespace(),
        self::blogSegment(),
        self::userSegment($userId),
        self::referenceSegment($reference),
        self::contextSegment(),
        self::versionSegment(),
    ]));
}

private static function contextSegment(): string { ... }  // from StarCacheContext
private static function versionSegment(): string { ... }  // from StarVersionStore
private static function referenceSegment(string $ref): string { ... } // with length guard

```

* * * * *

StarVersionStore --- Invalidation
-------------------------------

**Responsibility:** Version-based cache invalidation by group. Produces a version token that is embedded in cache keys. Bumping the version for a group logically invalidates all keys in that group without touching the storage backend.

**Why version-based, not flush-based:**

A global cache flush on content change causes a thundering herd --- every request simultaneously misses the cache and hits the database. Version bumping is a logical invalidation: existing cache entries become unreachable (their key no longer matches because the version segment changed) but are evicted naturally by TTL rather than all at once.

**Groups:**

```
GROUP_PAGES    --- full-page cache entries
GROUP_QUERIES  --- WP_Query results
GROUP_OBJECTS  --- arbitrary object cache entries

```

**WP-CLI integration:**

```
wp starcache flush   # bumps all group versions --- NOT a global flush
wp starcache status  # shows active backend + current context

```

* * * * *

StarPageCache --- Full-Page Cache
-------------------------------

**Responsibility:** Output buffering and full-page cache storage. Headers are not StarPageCache's concern --- that is StarResponseController.

**Hard boundary:**

```
StarPageCache         → ob_start() / ob_get_clean() / cache storage
StarResponseController → Cache-Control / Vary / X-Cache headers

```

If StarPageCache is currently sending or modifying headers, that code must move to StarResponseController. The boundary is absolute.

**Bypass conditions (StarPageCache must not cache):**

-   `StarCacheContext::shouldBypass()` returns true (authenticated user)
-   Request method is not GET
-   WooCommerce cart is non-empty
-   WordPress admin request (`is_admin()`)
-   Response already has a `Cache-Control: no-cache` or `no-store` header

**Invalidation on content change:**

```
save_post              → purgeOnSave($postId, $post)
transition_post_status → purgeOnStatusChange($newStatus, $oldStatus, $post)
trashed_post           → purgeOnSave() via post object
before_delete_post     → purgeOnSave() via post object

```

Purge strategy: bump the relevant version group. Also send a Varnish PURGE request if `VARNISH_HOST` is defined.

* * * * *

StarResponseController --- HTTP Response Headers
----------------------------------------------

**Responsibility:** Apply Cache-Control, Vary, and X-Cache headers. Nothing else. StarResponseController does not store data, does not read from cache, does not modify output.

**Gating order (strict --- do not reorder):**

```
1\. Check StarCacheContext::shouldBypass() --- authenticated = no cache headers
2. Check request method --- non-GET/HEAD = no cache headers
3. Check DONOTCACHEPAGE --- explicit no-cache flag wins
4. Check existing headers --- never override Cache-Control/Expires already set
5. Check `starcache_bypass_page_cache` filter --- plugin/theme opt-out
6. Apply Cache-Control: public, max-age={ttl}, stale-while-revalidate={swr}
7. Apply Vary: Cookie, Accept-Encoding (plus context-driven Vary values)
8. Apply X-StarCache-Context: {context_hash} (debug header)

```

**Current assessment: VIP-level correctness.** Do not modify the gating order without a documented reason. Every step exists to prevent a specific class of caching bug.

* * * * *

StarCache --- Public API Facade
-----------------------------

**Responsibility:** Provide a clean public interface for themes and plugins to use cache-aside patterns without knowing which backend is active.

**Global helper functions (WordPress integration points --- the only permitted global functions in this codebase):**

```
star_cache(): StarCache                      --- singleton instance
star_cache_get(string $reference, ?string $userId = null): mixed
star_cache_set(mixed $data, string $reference, int $ttl = 0, ?string $userId = null): bool
star_cache_delete(string $reference, ?string $userId = null): bool
star_cache_remember(string $reference, callable $callback, int $ttl = 3600, ?string $userId = null): mixed

```

**The `star_cache_remember()` pattern is the correct pattern for query caching.** Any code that currently uses `StarQueryCache` as a system should be replaced with `star_cache_remember()`:

```
// Before (wrong --- StarQueryCache as a system)
// posts_pre_query / the_posts filter hooks

// After (correct --- cache-aside)
$posts = star_cache_remember(
    'homepage_posts',
    fn() => get_posts(['post_type' => 'post', 'numberposts' => 10]),
    3600
);

```

### star_cache_remember() error contract

StarCache does not catch or suppress exceptions thrown by the callback. Exceptions propagate to the caller unchanged. No cache entry is written when the callback throws.

```
callback throws → exception propagates to caller
                → no cache write occurs
                → no partial state stored
                → caller is responsible for fallback via try/catch

```

This is a deliberate design decision. Swallowing callback exceptions causes silent failures --- bad data gets cached, callers lose control, and debugging becomes impossible. Deterministic behavior requires that failures are visible.

If a caller needs fallback behavior, it wraps the call:

```
try {
    $result = star_cache_remember('key', fn() => expensive_operation(), 3600);
} catch (\Throwable $e) {
    $result = default_fallback_value();
}

```

StarCache never provides this wrapping internally.

* * * * *

StarQueryCache --- REMOVAL REQUIRED
---------------------------------

`StarQueryCache` currently hooks `posts_pre_query` and `the_posts` filters to intercept and cache WP_Query results as a system-level concern.

**This must be removed.** The correct pattern is `star_cache_remember()`.

**Why:**

-   Duplicates keying logic already handled by `StarCacheKey`
-   Creates inconsistent invalidation paths (two systems, not one)
-   Hooks into WordPress query pipeline in ways that conflict with plugins that modify queries legitimately
-   The `star_cache_remember()` pattern achieves identical results with zero additional infrastructure

**Migration path:**

1.  Identify all callers of `StarQueryCache` directly or via filter hooks
2.  Replace with `star_cache_remember()` at the call site
3.  Remove `StarQueryCache.php` and its hooks from `starcache.php`
4.  Bump minor version when complete

* * * * *

StarAssetMinifier --- EXTRACTION REQUIRED
---------------------------------------

`StarAssetMinifier` provides CSS/JS minification. This functionality is correct and useful but does not belong in the MU-plugin core.

**Why extraction is required:**

-   Asset minification has a completely different concern from cache orchestration. Mixing them violates single responsibility.
-   The minifier hooks `wp_print_styles` and `wp_print_scripts` --- these hooks interact with the asset pipeline in ways that can conflict with build tools like `@wordpress/scripts`
-   Not every StarCache deployment needs asset minification

**Extraction plan:**

-   Move to `sparxstar-starcache-assets` as a companion plugin
-   StarCache core loads it only if it is present
-   Core StarCache ships without asset minification
-   Existing functionality is preserved --- it moves, it does not disappear

Until extraction is complete: `StarAssetMinifier` remains in the codebase but is noted as a future extraction target. Do not add features to it.

* * * * *

Configuration reference
-----------------------

All configuration via `wp-config.php` constants. No database options. No WordPress options table. Constants are set before WordPress loads --- they are available at `plugins_loaded` priority 0.

```
// Redis
define('WP_REDIS_HOST',     '127.0.0.1');
define('WP_REDIS_PORT',     6379);
define('WP_REDIS_PASSWORD', 'secret');
define('WP_REDIS_DATABASE', 0);

// Memcached
define('MEMCACHED_SERVERS', [['host' => '127.0.0.1', 'port' => 11211]]);

// Varnish
define('VARNISH_HOST', '127.0.0.1');
define('VARNISH_PORT', 6081);

// Asset minification (until StarAssetMinifier is extracted)
define('STARCACHE_ASSET_DIR', '/var/www/html/wp-content/cache/starcache/assets');
define('STARCACHE_ASSET_URL', 'https://example.com/wp-content/cache/starcache/assets');
define('STARCACHE_MINIFY',    false); // set false to disable

```

* * * * *

Backend detection and TTL defaults
----------------------------------

```
Backend          Default TTL    Notes
Redis            3600s          Persistent. Preferred for production.
Memcached        3600s          Persistent. Second choice.
Memcache         3600s          Legacy. Avoid for new deployments.
WP object cache  3600s          Persistent only if Redis/Memcached drop-in active.
                                Non-persistent = in-memory per request only.

```

OPcache accelerates PHP file execution. It is not a data cache for StarCache but is detected and reported in admin bar status. It provides no TTL management --- it caches compiled PHP opcodes, not application data.

* * * * *

Multisite support
-----------------

`blog_id` from `get_current_blog_id()` is embedded in every cache key. This ensures complete isolation between sites in a multisite network --- a cache entry for site 1 cannot collide with a cache entry for site 2 even if they cache the same URL path.

`star_cache_flush` via WP-CLI bumps versions for the current blog only. Network-wide flush requires running the command on each blog or using `wp site list --field=url` to iterate.

* * * * *

Testing
-------

PHPUnit test suite with WordPress function stubs in `tests/bootstrap.php`. The bootstrap provides in-memory shims for:

-   `wp_cache_get/set/delete/flush/delete_group`
-   `get_transient/set_transient/delete_transient`
-   `get_current_blog_id`, `is_user_logged_in`, `is_admin`, `is_ssl`
-   `apply_filters`, `add_action`, `add_filter`, `do_action`
-   `sanitize_key`, `esc_html`, `home_url`, `site_url`
-   `wp_generate_password` (test stub only --- NOT cryptographically secure)

```
composer install
composer test        # full suite
composer lint        # PHP_CodeSniffer PSR-12
composer analyze     # PHPStan Level 5

```

**PHPStan Level 5 must pass before any merge.** This is a CI gate.

* * * * *

Hard rules
----------

-   `declare(strict_types=1)` in every file
-   No global functions except the five `star_cache_*` helpers in `starcache.php`
-   `StarCacheAdapter` does not build keys, decide TTLs, or evaluate context
-   `StarPageCache` does not set HTTP headers
-   `StarResponseController` does not store or retrieve cached data
-   Context dimensions must be set before `plugins_loaded` completes
-   Nothing modifies context after `plugins_loaded` --- verified on every PR
-   Cache key references must be under 250 characters --- enforced with exception
-   Authenticated users never receive cached responses --- enforced by `shouldBypass()`
-   Version bumps, not global flush --- thundering herd prevention
-   `StarQueryCache` as a system: removal required before v3.0
-   `StarAssetMinifier` in core: extraction required before v3.0

* * * * *

Hardening checklist (current state --- v2.1.1)
--------------------------------------------

These items are from the architectural review. Each must be resolved before v3.0 release.

**This table was stale as of the v2.1.1 governance review (2026-07-01) ---
several rows below were still marked "Required" after the code had
already implemented them.** Verified against the current code
(`StarCacheContext.php`, `StarCacheKey.php`, `StarCacheAdapter.php`,
`starcache.php`) and updated accordingly.

| Item | Status | Priority |
| --- | --- | --- |
| Remove StarQueryCache system → Cache::remember() | Done --- hooks not registered in starcache.php; only the standalone `cachedWpdbQuery()` helper remains, marked deprecated | 1 |
| Lock context dimensions --- max count + value length | Done --- `StarCacheContext::MAX_DIMENSIONS` (7), `MAX_DIMENSION_VALUE_LENGTH` (64), charset sanitization, and the `register()` model are implemented and tested | 2 |
| Adapter discipline --- remove key/TTL/context logic | Done --- `StarCacheAdapter` has no reference to `StarCacheKey` or `StarCacheContext`; it only hashes group names for its own storage namespacing | 3 |
| KeyBuilder responsibility separation | Done --- `StarCacheKey::build()` delegates to named segment methods as specified | 4 |
| PageCache / ResponseController hard boundary | Required | 5 |
| Context lock timing audit --- nothing after plugins_loaded | Required | 6 |
| Reference length guard in StarCacheKey | Done --- `MAX_REFERENCE_LENGTH = 250`, enforced with an exception, and tested | 7 |
| Extract StarAssetMinifier to companion plugin | Required | 8 |

* * * * *

Security --- cache poisoning surface
----------------------------------

Context dimensions are a security boundary, not just a cache feature. The `starcache_context_dimensions` filter is an open injection point --- any WordPress plugin can hook it and add arbitrary dimensions.

### Attack surface

Without constraints, a malicious or badly-written plugin can:

-   **Cache key explosion** --- inject unbounded dimension combinations, causing every request to become a distinct cache entry
-   **Memory exhaustion** --- inject large dimension values that bloat Redis or Memcached key storage
-   **Cache bypass** --- inject a unique dimension per request, causing 100% cache miss rate
-   **Denial-of-service** --- combine the above at scale

### Required mitigations (all mandatory before production)

**Maximum dimension count:** 7\. Any dimensions beyond 7 are dropped and logged. Not silently accepted, not an exception --- logged and dropped.

**Maximum value length:** 64 characters. Values longer than 64 chars are truncated to 64 before hashing.

**Allowed characters:** `[a-z0-9_:-]` only. Values not matching this pattern are sanitized before use --- not rejected, sanitized.

**Dimension registration model:** Custom dimensions must be registered with the Context Engine before they can be set. Unregistered dimensions injected via `starcache_context_dimensions` are ignored.

```
// Registering a custom dimension (correct)
StarCacheContext::register('locale', ['en', 'fr', 'es', 'de']);

// Injecting an unregistered dimension via filter (ignored)
add_filter('starcache_context_dimensions', function($dims) {
    $dims['arbitrary_key'] = 'arbitrary_value'; // ignored --- not registered
    return $dims;
});

```

The registered value list is optional. If provided, only registered values are accepted --- others are normalized to a default. If not provided, any value matching `[a-z0-9_:-]` and under 64 chars is accepted for that dimension.

**This moves context from an open extension point to a controlled input system.** Any code review that introduces a new dimension must also show its registration call.

What StarCache does not do
--------------------------

-   StarCache does not interact with SPARXSTAR governance components
-   StarCache does not use Helios, Sirus, Mehns, or Dheghom
-   StarCache does not enforce content sovereignty --- that is SPARXSTAR's domain
-   StarCache does not cache authenticated user responses --- ever
-   StarCache does not perform a global cache flush on invalidation events
-   StarCache does not store configuration in the WordPress database

### SPARXSTAR content state boundary

StarCache invalidation is driven by WordPress hooks: `save_post`, `transition_post_status`, `clean_post_cache`. This covers all content state changes that flow through the WordPress post lifecycle.

**Critical question:** When Dheghom quarantines a record, does it update `post_status` in WordPress? If yes, `transition_post_status` fires and StarCache invalidates automatically. If no --- if the quarantine state change happens below the WordPress post layer --- StarCache is not aware and may serve a cached page of quarantined content until TTL expiry.

**This boundary must be explicitly verified and one of these two models must be declared:**

**Option A (preferred):** Dheghom quarantine always updates WordPress `post_status`. `transition_post_status` fires. StarCache invalidates automatically. No additional integration required.

**Option B (required if Option A is not true):** Any SPARXSTAR component that modifies content state outside of the WordPress post lifecycle must explicitly call StarCache invalidation:

```
// On quarantine event outside WordPress post lifecycle
StarVersionStore::bump(StarVersionStore::GROUP_PAGES);
// or for a specific URL
StarPageCache::purgeUrl($affectedUrl);

```

Do not assume Option A is true without verification. Document the answer in this spec when it is confirmed.

* * * * *

Open items
----------

-   [OPEN] Context dimension whitelist --- should custom dimensions be restricted to a registered set, or is max-count + max-length sufficient?
-   [OPEN] Varnish PURGE authentication --- if Varnish requires a secret key for PURGE requests, where does that key live? (not wp-config.php, not options)
-   [OPEN] Stale-while-revalidate --- should StarCache support serving a stale cached response while the backend fetches a fresh one?
-   [OPEN] Edge cache integration --- Cloudflare-specific Cache-Control directives (`s-maxage`, `stale-while-revalidate`) are not yet specified
-   [OPEN] Per-route TTL configuration --- currently TTL is global. Should specific URL patterns support different TTLs?

* * * * *

*Context is resolved before anything runs. Keys are deterministic. Invalidation is version-based. Headers are the last thing applied. Authenticated users bypass everything. This order does not change.*
