---
product_id: starcache
name: "StarCache"
status: review
version: "2.1.1"
owner: "@MaximillianGroup"
last_reviewed: "2026-07-01"
spec_id_prefix: "STARCACHE"
---

# StarCache — Product Tech Spec

This is a first-draft spec bootstrapped from the existing code and from
`TECHNICAL-SPECIFICATION.md` (the repo's pre-existing internal design
doc, kept in place as detailed engineering reference). It documents what
the code does today, not aspirational behavior. Items the code does not
yet implement are called out as `OQ-*` / gaps, not silently assumed.

## Identity

StarCache is a deterministic cache orchestration engine for WordPress,
shipped as a Must-Use (MU) plugin. It is not itself a cache — it
orchestrates context resolution, backend selection, key construction,
response headers, and invalidation into one disciplined system on top of
Redis, Memcached, Memcache, or the WordPress object cache.

- Repo: `Starisian-Technologies/StarCache`
- Product key: `starcache`
- License: Apache 2.0
- Language/runtime: PHP 8.2+, `declare(strict_types=1)` in every file
- Owner: MaximillianGroup (Max Barrett) — see YAML frontmatter `owner`

### REQ-001 — Standalone product boundary

StarCache MUST NOT depend on, import, or reference any SPARXSTAR
component (Dheghom, Helios, Sirus, Mehns). It is a general-purpose
WordPress product built to the same engineering standards, not a
SPARXSTAR integration.

## Role boundary

See `ROLE.md` for the canonical owns/does-not-own statement. Summary:
StarCache owns cache backend storage, context resolution, key
construction, invalidation, full-page/fragment caching, response
headers, transients, and (for now) asset minification. It does not own
content sovereignty, database-stored configuration, or global cache
flushing as a production strategy.

## Platform citations

StarCache predates this org's governance system and currently has no
ADRs, invariants, or open questions assigned to it in
`sparxstar-architecture-governance-registry`. Once
`.github/instructions/governance/` is populated by the governance-sync
workflow, this section must be updated to cite the relevant ADR/invariant
IDs. Until then, the authoritative internal design constraints live in
`TECHNICAL-SPECIFICATION.md` in this repo and are restated as `REQ-*`
below.

### OQ-001 — No governance snapshot yet

`.github/instructions/governance/` has not been populated for this repo
as of this draft. Open until the ADR registry's governance-sync workflow
runs against this repo.

## Architecture

Seven core components, each with exactly one concern:

| Component | Responsibility |
|---|---|
| `StarCacheContext` | Resolve/hold request context dimensions (device, auth, experiment); produce the context hash |
| `StarCacheAdapter` | Backend detection (Redis → Memcached → Memcache → WP object cache) and raw get/set/delete only |
| `StarCacheKey` | Deterministic, collision-resistant cache key construction (SHA-256) |
| `StarVersionStore` | Version-bump invalidation by group (`GROUP_PAGES`, `GROUP_QUERIES`, `GROUP_OBJECTS`) |
| `StarPageCache` | Full-page output-buffer caching + fragment caching; Varnish PURGE on invalidation |
| `StarResponseController` | `Cache-Control` / `Vary` / `X-Cache*` response headers only |
| `StarCache` | Public API facade — cache-aside pattern (`star_cache_*` helpers) |

Two additional components with defined future status:

| Component | Status |
|---|---|
| `StarTransientCache` | Stable, active — per-site and network-wide (multisite) transient helpers |
| `StarAssetMinifier` | CSS/JS minification — **extraction to a companion plugin required before v3.0** (see Open items) |

### REQ-002 — Component boundaries are hard

- `StarCacheAdapter` MUST NOT build keys, decide TTLs, or evaluate
  context.
- `StarPageCache` MUST NOT set HTTP headers.
- `StarResponseController` MUST NOT store or retrieve cached data.

### AC-001 — Hook lifecycle order

Given a normal WordPress request, the following hook order MUST hold:
`plugins_loaded@0` (`StarCacheContext::resolve`) →
`plugins_loaded@1` (`StarCacheAdapter::init`) →
`init@1` (`StarPageCache::startPageCache`) →
`send_headers@999` (`StarCacheContext::lock` then
`StarResponseController::apply`). Verified by inspection of
`starcache.php`; not currently covered by an automated integration test
(see Current state).

### AC-002 — MU-plugin release layout

The release archive MUST place the WordPress-discoverable `starcache.php`
loader at the MU-plugin root and companion class files in the adjacent
`starcache/` directory. The loader MUST also support the legacy flat and
Composer layouts where its companion classes are beside the loader. An
incomplete installation MUST fail with a descriptive exception naming the
missing file rather than an unqualified `require_once` warning.

## Data model

StarCache stores no data in the WordPress database (no options, no
custom tables). All state is either:

- Ephemeral, backend-stored cache entries (Redis/Memcached/Memcache/WP
  object cache), keyed by `StarCacheKey::build()`.
- In-process static state for the current request (`StarCacheContext`
  dimensions, until locked).
- Version counters per invalidation group, stored via the active backend
  under `StarVersionStore`'s own keys.

Cache key composition: `namespace | blog_id | user_segment | reference |
context_hash | version`, SHA-256'd as the final key. `blog_id` comes from
`get_current_blog_id()` for multisite isolation.

## API surface

Public global helper functions (the only global functions permitted in
this codebase):

```
star_cache(): \StarCache\StarCache
star_cache_get(string $reference, ?string $userId = null): mixed
star_cache_set(mixed $data, string $reference, int $ttl = 0, ?string $userId = null): bool
star_cache_delete(string $reference, ?string $userId = null): bool
star_cache_remember(string $reference, callable $callback, int $ttl = 3600, ?string $userId = null): mixed
```

Namespaced public surfaces used directly by themes/plugins:

- `\StarCache\StarPageCache::getFragment()` / `::saveFragment()` / `::purgeUrl()`
- `\StarCache\StarTransientCache::star_setNetworkCachedData()` / `::star_getNetworkCachedData()`
- `\StarCache\StarQueryCache::cachedWpdbQuery()` — **deprecated**, see Open items
- WP-CLI: `wp starcache flush`, `wp starcache status`

Filters/actions: `starcache_bypass_page_cache`, `starcache_page_ttl`,
`starcache_query_ttl`, `starcache_cache_query`, `starcache_varnish_enabled`,
`starcache_minify_enabled`, `starcache_after_purge`,
`starcache_after_query_invalidate`, `starcache_context_dimensions`.

### REQ-003 — Callback exceptions are not swallowed

`star_cache_remember()` MUST propagate exceptions thrown by its callback
to the caller unchanged, and MUST NOT write a cache entry when the
callback throws.

## Seams

- **WordPress hook system** — the only integration point; StarCache
  reacts to `plugins_loaded`, `init`, `send_headers`, `save_post`,
  `transition_post_status`, `trashed_post`, `before_delete_post`,
  `clean_post_cache`, `updated_post_meta`, `set_object_terms`,
  `updated_option`, `upgrader_process_complete`, `switch_theme`.
- **Cache backends** — Redis (PhpRedis or Predis), Memcached, Memcache,
  WordPress object cache. Detected at `plugins_loaded@1`; no runtime
  backend switching within a request.
- **Varnish** (optional) — HTTP `PURGE` requests on invalidation, gated
  by `VARNISH_HOST`/`VARNISH_PORT` constants.
- **WP-CLI** (optional) — registered only when `WP_CLI` is defined.

### OQ-002 — Content-state boundary with SPARXSTAR consumers

If a future SPARXSTAR component (e.g. Dheghom) ever changes content
state outside the WordPress post lifecycle (not via `post_status`), that
component — not StarCache — is responsible for calling
`StarVersionStore::bump()` or `StarPageCache::purgeUrl()` explicitly.
Open until a concrete consumer needs this; StarCache does not currently
have any such consumer per `REQ-001`.

## Dependencies

Runtime: PHP 8.2+, WordPress (any version providing the hooks listed
above). Optional PHP extensions: `redis` (PhpRedis) or `predis/predis`,
`memcached`, `memcache`. No required Composer packages beyond PHP itself
(`composer.json` `require.php: ^8.2`) — StarCache's own dependency tree
has no private packages. `standards.yml` and `governance.yml` still pass
`COMPOSER_RESOLVER_PRIVATE_KEY` through to the org's reusable
`php-enforcement.yml` / `fetch-specs.yml` workflows, per the platform
template — that secret is for those reusable workflows' own use, not
because this repo's `composer install` needs private-package auth.

Dev-only: `phpunit/phpunit ^10.0`, `squizlabs/php_codesniffer ^3.9`,
`phpstan/phpstan ^2.0` — all public packages.

## Security and privacy

- Authenticated requests MUST NOT receive cached responses
  (`StarCacheContext::shouldBypass()`); the auth dimension is excluded
  from the context hash so it can never leak into a cache key.
- Global backend flush (`StarCacheAdapter::flush()`) is disabled by
  default; requires `STARCACHE_ALLOW_DANGEROUS_FLUSH` to enable, and is
  documented as dev/test tooling only, never a production invalidation
  path.
- Invalidation is version-bump based, not global flush, specifically to
  avoid thundering-herd DB load on content change.
- No secrets or credentials are stored by StarCache itself; Redis/Varnish
  credentials are read from `wp-config.php` constants, not from the
  database.

### REQ-004 — Context dimension hardening

`TECHNICAL-SPECIFICATION.md` lists context-dimension limits as
"HARDENING REQUIRED," but verification against the current code
(`StarCacheContext.php`) confirms this is already fully implemented,
including test coverage in `tests/UnitTests.php`:

- Maximum dimension count: 7 (`MAX_DIMENSIONS`). Excess dimensions are
  dropped and logged, not silently accepted.
- Maximum value length: 64 characters (`MAX_DIMENSION_VALUE_LENGTH`).
  Longer values are truncated before hashing.
- Allowed characters: `[a-z0-9_:-]` only; non-matching values are
  sanitized, not rejected.
- Dimension registration model: custom dimensions must be registered via
  `StarCacheContext::register()` before they can be set. Unregistered
  dimensions injected via the `starcache_context_dimensions` filter are
  ignored.

`TECHNICAL-SPECIFICATION.md`'s hardening checklist table is stale on
this point and should be updated separately to mark the item complete.

## Current state

- Core caching, invalidation, and header logic (`StarCacheContext`,
  `StarCacheAdapter`, `StarCacheKey`, `StarVersionStore`, `StarPageCache`,
  `StarResponseController`, `StarCache`) is implemented and in active use
  at v2.1.1.
- `StarTransientCache` is stable and active.
- `StarQueryCache` is present but deprecated — its hook-based
  `posts_pre_query`/`the_posts` registration is intentionally NOT wired
  in `starcache.php`; only the standalone `cachedWpdbQuery()` helper
  remains callable directly.
- `StarAssetMinifier` is present and active but flagged in
  `TECHNICAL-SPECIFICATION.md` for extraction to a companion plugin
  before v3.0.
- Test suite: `tests/UnitTests.php` with WordPress function stubs in
  `tests/bootstrap.php`. `composer test` / `composer lint` / `composer
  analyze` (PHPStan) are wired; `phpstan.neon` currently runs at level 9
  (note: `TECHNICAL-SPECIFICATION.md` states level 5 as the standard —
  this spec reflects the actual configured level 9 and flags the
  discrepancy as a doc-drift item to resolve, not a code defect).

## Open items

- `OQ-001` — no governance snapshot populated yet for this repo.
- `OQ-002` — content-state boundary with future SPARXSTAR consumers is
  unresolved (no consumer exists today).
- `OQ-003` — Varnish PURGE authentication: if Varnish requires a secret
  for PURGE requests, where does that secret live? Not resolved in
  `wp-config.php` or the options table today.
- `OQ-004` — per-route TTL configuration is not supported; TTL is global
  per cache type.
- Removal of `StarQueryCache` as a system, and extraction of
  `StarAssetMinifier` to a companion plugin, are both tracked as
  required-before-v3.0 in `TECHNICAL-SPECIFICATION.md`'s hardening
  checklist.

## Changelog

- 2026-09-18 — Corrected the MU-plugin release layout and loader discovery
  contract so the root loader resolves companion classes from `starcache/`
  while retaining flat/Composer installation compatibility.
- 2026-07-01 — Initial spec bootstrapped from `TECHNICAL-SPECIFICATION.md`
  and the current codebase (v2.1.1). Status set to `review`; proposed to
  the spec registry for canonical promotion. Fixed `owner` handle and
  OQ numbering gap (`OQ-003`/`OQ-004` restored).
