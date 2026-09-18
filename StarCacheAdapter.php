<?php

declare(strict_types=1);

namespace StarCache;

if (!defined('ABSPATH')) {
    exit;
}

use Exception;

/**
 * StarCacheAdapter
 *
 * Auto-detects and initialises the best available cache backend:
 * Redis → Memcached → Memcache → WordPress object cache (APCu / file / DB).
 * OPcache is reported but managed by PHP itself; this class exposes a helper
 * to check its status.
 *
 * Connection parameters are read from WordPress constants when defined:
 *   WP_REDIS_HOST, WP_REDIS_PORT, WP_REDIS_PASSWORD, WP_REDIS_DATABASE
 *   MEMCACHED_SERVERS (array of ['host', 'port'] pairs)
 *   MEMCACHE_SERVER_HOST / MEMCACHE_SERVER_PORT
 *
 * @package StarCache
 * @author  MaximillianGroup (Max Barrett) <maximilliangroup@gmail.com>
 * @version 2.1.1
 * @license Apache 2.0
 */
class StarCacheAdapter
{
    public const BACKEND_REDIS     = 'redis';
    public const BACKEND_MEMCACHED = 'memcached';
    public const BACKEND_MEMCACHE  = 'memcache';
    public const BACKEND_WP        = 'wp';

    private const DEFAULT_GROUP = 'default';
    private const PONG_TRIM_CHARS = " \t\n\r\0\x0B+";

    /** @var \Redis|\Predis\Client|\Memcached|\Memcache|null */
    private static $connection = null;

    /** @var string */
    private static string $detectedBackend = self::BACKEND_WP;

    /** @var bool */
    private static bool $initialised = false;

    /** @var array<string,string> */
    private static array $groupHashCache = [];

    /**
     * Initialise the adapter (idempotent – safe to call multiple times).
     */
    public static function init(): void
    {
        if (self::$initialised) {
            return;
        }

        self::$initialised = true;

        try {
            if (self::tryRedis()) {
                return;
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter init redis probe error', $e);
        }
        try {
            if (self::tryPredis()) {
                return;
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter init predis probe error', $e);
        }
        try {
            if (self::tryMemcached()) {
                return;
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter init memcached probe error', $e);
        }
        try {
            if (self::tryMemcache()) {
                return;
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter init memcache probe error', $e);
        }

        // Fallback: WordPress built-in object cache (wp_cache_*)
        self::$detectedBackend = self::BACKEND_WP;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns the name of the active backend.
     */
    public static function getBackend(): string
    {
        return self::$detectedBackend;
    }

    /**
     * Returns the raw connection object (backend-specific) or null when the
     * WordPress object cache fallback is used.
     *
     * @return object|null
     */
    public static function getConnection(): object|null
    {
        return self::$connection;
    }

    /**
     * Return backend capability information for operational visibility.
     *
     * @return array<string,bool|string>
     */
    public static function getBackendCapabilities(): array
    {
        return [
            'phpredis_available'    => extension_loaded('redis'),
            'predis_available'      => class_exists('\Predis\Client'),
            'memcached_available'   => extension_loaded('memcached'),
            'memcache_available'    => extension_loaded('memcache'),
            'wp_object_cache_add'   => function_exists('wp_cache_add'),
            'wp_object_cache_flush' => function_exists('wp_cache_flush'),
            'wp_object_cache_mode'  => self::detectWpObjectCacheMode(),
            'active_backend'        => self::getBackend(),
        ];
    }

    /**
     * Returns true when OPcache is enabled and functioning.
     */
    public static function isOpcacheEnabled(): bool
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }
        $status = @opcache_get_status(false);
        return is_array($status) && !empty($status['opcache_enabled']);
    }

    /**
     * Retrieve a value from the active cache backend.
     *
     * @param string $key
     * @param string $group  Used for namespacing across all cache backends;
     *                       maps to a WP cache group for the WP object cache backend.
     * @return mixed
     */
    public static function get(string $key, string $group = ''): mixed
    {
        $result = self::getWithFound($key, $group);
        return $result['found'] ? $result['value'] : false;
    }

    /**
     * Retrieve a value plus an explicit hit/miss indicator.
     *
     * @param  string $key
     * @param  string $group Used for namespacing across all cache backends;
     *                       maps to a WP cache group for the WP object cache backend.
     * @return array{found: bool, value: mixed}
     */
    public static function getWithFound(string $key, string $group = ''): array
    {
        try {
            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    $connection = self::redisConnection();
                    if ($connection === null) {
                        return ['found' => false, 'value' => false];
                    }

                    $storageKey = self::buildStorageKey($key, $group);
                    $value      = $connection->get($storageKey);
                    if ($value === false || $value === null) {
                        return ['found' => false, 'value' => false];
                    }
                    if (!is_string($value)) {
                        return ['found' => false, 'value' => false];
                    }
                    $unserialized = unserialize($value, ['allowed_classes' => false]);
                    if ($unserialized === false && $value !== serialize(false)) {
                        return ['found' => false, 'value' => false];
                    }
                    return ['found' => true, 'value' => $unserialized];

                case self::BACKEND_MEMCACHED:
                    $connection = self::memcachedConnection();
                    if ($connection === null) {
                        return ['found' => false, 'value' => false];
                    }

                    $storageKey = self::buildStorageKey($key, $group);
                    $value      = $connection->get($storageKey);
                    if ($value === false && $connection->getResultCode() === \Memcached::RES_NOTFOUND) {
                        return ['found' => false, 'value' => false];
                    }
                    // Value was stored as a serialized string; unserialize to recover the original.
                    if (!is_string($value)) {
                        return ['found' => false, 'value' => false];
                    }
                    $unserialized = unserialize($value, ['allowed_classes' => false]);
                    if ($unserialized === false && $value !== serialize(false)) {
                        return ['found' => false, 'value' => false];
                    }
                    return ['found' => true, 'value' => $unserialized];

                case self::BACKEND_MEMCACHE:
                    $connection = self::memcacheConnection();
                    if ($connection === null) {
                        return ['found' => false, 'value' => false];
                    }

                    // Memcache::get() returns false on miss AND when the stored value is literally
                    // false. Values are stored serialized so a retrieved string is always a hit.
                    $storageKey = self::buildStorageKey($key, $group);
                    $value      = $connection->get($storageKey);
                    if ($value === false) {
                        // Cache miss; serialized values are stored as strings, never literal false.
                        return ['found' => false, 'value' => false];
                    }
                    if (!is_string($value)) {
                        return ['found' => false, 'value' => false];
                    }
                    $unserialized = unserialize($value, ['allowed_classes' => false]);
                    if ($unserialized === false && $value !== serialize(false)) {
                        return ['found' => false, 'value' => false];
                    }
                    return ['found' => true, 'value' => $unserialized];

                default:
                    $found = false;
                    $value = wp_cache_get($key, $group, false, $found);
                    return ['found' => (bool) $found, 'value' => $found ? $value : false];
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::getWithFound', $e);
            return ['found' => false, 'value' => false];
        }
    }

    /**
     * Store a value in the active cache backend.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $expiration  Seconds (0 = no expiry for WP/Redis).
     * @param string $group       Used for namespacing across all cache backends;
     *                            maps to a WP cache group for the WP object cache backend.
     * @return bool
     */
    public static function set(string $key, mixed $value, int $expiration = 3600, string $group = ''): bool
    {
        try {
            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    $connection = self::redisConnection();
                    if ($connection === null) {
                        return false;
                    }

                    $storageKey = self::buildStorageKey($key, $group);
                    $serialised = serialize($value);
                    if ($expiration > 0) {
                        if (self::isPredisConnection()) {
                            return self::isSuccessfulSetResult(
                                $connection->setex($storageKey, $expiration, $serialised)
                            );
                        }
                        return (bool) $connection->setEx($storageKey, $expiration, $serialised);
                    }
                    if (self::isPredisConnection()) {
                        return self::isSuccessfulSetResult($connection->set($storageKey, $serialised));
                    }
                    return (bool) $connection->set($storageKey, $serialised);

                case self::BACKEND_MEMCACHED:
                    $connection = self::memcachedConnection();
                    if ($connection === null) {
                        return false;
                    }

                    // Serialize to mirror the Redis strategy and allow any PHP value
                    // (including boolean false) to be stored and retrieved unambiguously.
                    return $connection->set(
                        self::buildStorageKey($key, $group),
                        serialize($value),
                        $expiration
                    );

                case self::BACKEND_MEMCACHE:
                    $connection = self::memcacheConnection();
                    if ($connection === null) {
                        return false;
                    }

                    // Memcache::set($key, $value, $flags, $expire) — 0 = no compression.
                    // Serialize for the same reason as Memcached above.
                    return $connection->set(
                        self::buildStorageKey($key, $group),
                        serialize($value),
                        0,
                        $expiration
                    );

                default:
                    return wp_cache_set($key, $value, $group, $expiration);
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::set', $e);
            return false;
        }
    }

    /**
     * Delete a cached value.
     *
     * @param string $key
     * @param string $group  Used for namespacing across all cache backends;
     *                       maps to a WP cache group for the WP object cache backend.
     */
    public static function delete(string $key, string $group = ''): bool
    {
        try {
            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    $connection = self::redisConnection();
                    if ($connection === null) {
                        return false;
                    }

                    return (bool) $connection->del(self::buildStorageKey($key, $group));

                case self::BACKEND_MEMCACHED:
                    $connection = self::memcachedConnection();
                    return $connection !== null && $connection->delete(self::buildStorageKey($key, $group));

                case self::BACKEND_MEMCACHE:
                    $connection = self::memcacheConnection();
                    return $connection !== null && $connection->delete(self::buildStorageKey($key, $group));

                default:
                    return wp_cache_delete($key, $group);
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::delete', $e);
            return false;
        }
    }

    /**
     * Flush all cache entries (dangerous; intended for local dev/test only).
     */
    public static function flush(): bool
    {
        if (!defined('STARCACHE_ALLOW_DANGEROUS_FLUSH') || STARCACHE_ALLOW_DANGEROUS_FLUSH !== true) {
            self::logMessage(
                'StarCacheAdapter::flush blocked. This is intended for '
                . 'development/testing only; use version bumps in production. '
                . 'Define STARCACHE_ALLOW_DANGEROUS_FLUSH=true in wp-config.php '
                . 'to enable.'
            );
            return false;
        }

        try {
            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    $connection = self::redisConnection();
                    if ($connection === null) {
                        return false;
                    }

                    if (self::isPredisConnection()) {
                        return self::isSuccessfulSetResult($connection->flushdb());
                    }
                    return (bool) $connection->flushDB();

                case self::BACKEND_MEMCACHED:
                    $connection = self::memcachedConnection();
                    return $connection !== null && $connection->flush();

                case self::BACKEND_MEMCACHE:
                    $connection = self::memcacheConnection();
                    return $connection !== null && $connection->flush();

                default:
                    return wp_cache_flush();
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::flush', $e);
            return false;
        }
    }

    /**
     * Atomically set only when absent where backend supports add/NX semantics.
     *
     * @param mixed $value
     */
    public static function add(string $key, mixed $value, int $expiration = 30, string $group = ''): bool
    {
        try {
            $storageKey = self::buildStorageKey($key, $group);
            $serialised = serialize($value);

            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    $connection = self::redisConnection();
                    if ($connection === null) {
                        return false;
                    }

                    if (self::isPredisConnection()) {
                        $options = ['NX'];
                        if ($expiration > 0) {
                            $options['EX'] = $expiration;
                        }
                        return self::isSuccessfulSetResult($connection->set($storageKey, $serialised, $options));
                    }

                    $options = ['NX'];
                    if ($expiration > 0) {
                        $options['EX'] = $expiration;
                    }
                    $result = $connection->set($storageKey, $serialised, $options);
                    return self::isSuccessfulSetResult($result);

                case self::BACKEND_MEMCACHED:
                    $connection = self::memcachedConnection();
                    return $connection !== null && $connection->add($storageKey, $serialised, $expiration);

                case self::BACKEND_MEMCACHE:
                    $connection = self::memcacheConnection();
                    return $connection !== null && $connection->add($storageKey, $serialised, 0, $expiration);

                default:
                    if (function_exists('wp_cache_add')) {
                        return wp_cache_add($key, $value, $group, $expiration);
                    }
                    $hit = self::getWithFound($key, $group);
                    if ($hit['found']) {
                        return false;
                    }
                    return self::set($key, $value, $expiration, $group);
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::add', $e);
            return false;
        }
    }

    /**
     * Close the underlying connection (no-op for WP cache).
     */
    public static function close(): void
    {
        if (self::$connection === null) {
            return;
        }
        try {
            switch (self::$detectedBackend) {
                case self::BACKEND_REDIS:
                    $connection = self::redisConnection();
                    if ($connection === null) {
                        break;
                    }

                    if (self::isPredisConnection()) {
                        if (method_exists($connection, 'disconnect')) {
                            $connection->disconnect();
                        }
                    } else {
                        $connection->close();
                    }
                    break;
                case self::BACKEND_MEMCACHED:
                    // Memcached connections are managed by the extension and do not
                    // expose a close() method.
                    break;
                case self::BACKEND_MEMCACHE:
                    $connection = self::memcacheConnection();
                    if ($connection !== null) {
                        $connection->close();
                    }
                    break;
            }
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::close', $e);
        }
        self::$connection = null;
    }

    // -------------------------------------------------------------------------
    // Backend detection
    // -------------------------------------------------------------------------

    private static function tryRedis(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }

        $host     = defined('WP_REDIS_HOST')     ? WP_REDIS_HOST     : '127.0.0.1';
        $port     = defined('WP_REDIS_PORT')     ? (int) WP_REDIS_PORT : 6379;
        $password = defined('WP_REDIS_PASSWORD') ? WP_REDIS_PASSWORD  : null;
        $database = defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : 0;

        $redis = new \Redis();

        if (!@$redis->connect($host, $port, 1.0)) {
            return false;
        }

        if ($password && !$redis->auth($password)) {
            return false;
        }

        if ($database !== 0) {
            $redis->select($database);
        }

        self::$connection     = $redis;
        self::$detectedBackend = self::BACKEND_REDIS;
        return true;
    }

    private static function tryPredis(): bool
    {
        if (!class_exists('\Predis\Client')) {
            return false;
        }

        $host     = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
        $port     = defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : 6379;
        $password = defined('WP_REDIS_PASSWORD') ? WP_REDIS_PASSWORD : null;
        $database = defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : 0;

        $params = [
            'scheme'   => 'tcp',
            'host'     => $host,
            'port'     => $port,
            'database' => $database,
        ];
        if (is_string($password) && $password !== '') {
            $params['password'] = $password;
        }

        try {
            $client = new \Predis\Client($params, ['exceptions' => false]);
            $pong   = $client->ping();
        } catch (Exception $e) {
            self::logError('StarCacheAdapter::tryPredis', $e);
            return false;
        }

        if (!self::isSuccessfulPredisPing($pong)) {
            return false;
        }

        self::$connection      = $client;
        self::$detectedBackend = self::BACKEND_REDIS;
        return true;
    }

    private static function tryMemcached(): bool
    {
        if (!extension_loaded('memcached')) {
            return false;
        }

        $memcached = new \Memcached();

        if (defined('MEMCACHED_SERVERS') && is_array(MEMCACHED_SERVERS)) {
            foreach (MEMCACHED_SERVERS as $server) {
                if (!is_array($server)) {
                    continue;
                }

                $serverHost = array_key_exists('host', $server) && is_string($server['host'])
                    ? $server['host']
                    : '127.0.0.1';
                $serverPort = array_key_exists('port', $server) && is_numeric($server['port'])
                    ? (int) $server['port']
                    : 11211;

                $memcached->addServer(
                    $serverHost,
                    $serverPort
                );
            }
        } else {
            $memcached->addServer('127.0.0.1', 11211);
        }

        // Verify connectivity via a trivial set/get
        $testKey = 'starcache_probe_' . wp_generate_password(8, false);
        $memcached->set($testKey, 1, 5);
        if ($memcached->getResultCode() !== \Memcached::RES_SUCCESS) {
            return false;
        }
        $memcached->delete($testKey);

        self::$connection      = $memcached;
        self::$detectedBackend = self::BACKEND_MEMCACHED;
        return true;
    }

    private static function tryMemcache(): bool
    {
        if (!extension_loaded('memcache')) {
            return false;
        }

        $host = defined('MEMCACHE_SERVER_HOST') ? MEMCACHE_SERVER_HOST : '127.0.0.1';
        $port = defined('MEMCACHE_SERVER_PORT') ? (int) MEMCACHE_SERVER_PORT : 11211;

        $memcache = new \Memcache();
        if (!@$memcache->connect($host, $port)) {
            return false;
        }

        self::$connection      = $memcache;
        self::$detectedBackend = self::BACKEND_MEMCACHE;
        return true;
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    /**
     * Build backend-internal namespaced key from logical key+group.
     */
    private static function buildStorageKey(string $key, string $group): string
    {
        $normalizedGroup = self::normaliseGroup($group);
        if (!array_key_exists($normalizedGroup, self::$groupHashCache)) {
            self::$groupHashCache[$normalizedGroup] = substr(hash('sha256', $normalizedGroup), 0, 16);
        }
        return 'scg:' . self::$groupHashCache[$normalizedGroup] . ':' . $key;
    }

    private static function normaliseGroup(string $group): string
    {
        $group = strtolower(trim($group));
        $sanitized = '';
        $length    = strlen($group);
        for ($i = 0; $i < $length; $i++) {
            $char = $group[$i];
            if (
                ($char >= 'a' && $char <= 'z')
                || ($char >= '0' && $char <= '9')
                || $char === '_'
                || $char === '-'
                || $char === ':'
            ) {
                $sanitized .= $char;
            }
        }
        $group = $sanitized;
        return $group !== '' ? $group : self::DEFAULT_GROUP;
    }

    private static function isPredisConnection(): bool
    {
        return class_exists('\Predis\Client') && self::$connection instanceof \Predis\Client;
    }

    private static function redisConnection(): \Redis|\Predis\Client|null
    {
        if (self::$connection instanceof \Redis) {
            return self::$connection;
        }

        if (class_exists('\Predis\Client') && self::$connection instanceof \Predis\Client) {
            return self::$connection;
        }

        return null;
    }

    private static function memcachedConnection(): ?\Memcached
    {
        return self::$connection instanceof \Memcached ? self::$connection : null;
    }

    private static function memcacheConnection(): ?\Memcache
    {
        return self::$connection instanceof \Memcache ? self::$connection : null;
    }

    private static function detectWpObjectCacheMode(): string
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return 'persistent';
        }
        return 'runtime';
    }

    private static function isSuccessfulSetResult(mixed $result): bool
    {
        // PhpRedis returns bool; Predis may return "OK" or status response objects.
        if ($result === true) {
            return true;
        }

        if (is_string($result)) {
            return strtoupper(trim($result, self::PONG_TRIM_CHARS)) === 'OK';
        }

        if (is_object($result) && method_exists($result, 'getPayload')) {
            $payload = $result->getPayload();
            if (is_string($payload)) {
                return strtoupper(trim($payload, self::PONG_TRIM_CHARS)) === 'OK';
            }
        }

        if (is_object($result) && method_exists($result, '__toString')) {
            return strtoupper(trim((string) $result, self::PONG_TRIM_CHARS)) === 'OK';
        }

        return false;
    }

    private static function isSuccessfulPredisPing(mixed $pong): bool
    {
        if ($pong === true) {
            return true;
        }

        if (is_string($pong)) {
            return strtoupper(trim($pong, self::PONG_TRIM_CHARS)) === 'PONG';
        }

        if (!is_object($pong)) {
            return false;
        }

        if (method_exists($pong, 'getPayload')) {
            $payload = $pong->getPayload();
            if (is_string($payload)) {
                return strtoupper(trim($payload, self::PONG_TRIM_CHARS)) === 'PONG';
            }
        }

        if (method_exists($pong, '__toString')) {
            return strtoupper(trim((string) $pong, self::PONG_TRIM_CHARS)) === 'PONG';
        }

        return false;
    }

    private static function logError(string $context, Exception $e): void
    {
        if (class_exists('\StarExceptionHandler')) {
            $logger = \StarExceptionHandler::star_getInstance();
            $logger->star_handleException($e);
        } else {
            error_log("[StarCache] {$context}: {$e->getMessage()}");
        }
    }

    private static function logMessage(string $message): void
    {
        $exception = new \RuntimeException($message);
        self::logError('StarCacheAdapter', $exception);
    }
}
