<?php
/**
 * CCM Tools Redis Object Cache Drop-In
 * 
 * A high-performance Redis-based object cache for WordPress.
 * Features: pipelining, SCAN-based flush, serializer/compression,
 * async flush, ACL auth, retry logic, flush logging, wp_cache_has(),
 * wp_cache_remember(), HTML footnote, KEEPTTL on incr/decr.
 * 
 * @package CCM_Tools
 * @version 7.19.0
 * 
 * This file should be placed in wp-content/object-cache.php
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Track errors globally (matches WP core pattern)
$wp_object_cache_errors = [];

// Initialize object cache
if (!defined('WP_CACHE_KEY_SALT')) {
    define('WP_CACHE_KEY_SALT', '');
}

/* ────────────────────────────────────────────────────────────────
 *  WordPress Object-Cache API Functions
 * ──────────────────────────────────────────────────────────────── */

function wp_cache_add($key, $data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->add($key, $data, $group, (int) $expire);
}

function wp_cache_add_multiple(array $data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->add_multiple($data, $group, (int) $expire);
}

function wp_cache_close() {
    global $wp_object_cache;
    return $wp_object_cache->close();
}

function wp_cache_decr($key, $offset = 1, $group = 'default') {
    global $wp_object_cache;
    return $wp_object_cache->decr($key, $offset, $group);
}

function wp_cache_delete($key, $group = 'default') {
    global $wp_object_cache;
    return $wp_object_cache->delete($key, $group);
}

function wp_cache_delete_multiple(array $keys, $group = 'default') {
    global $wp_object_cache;
    return $wp_object_cache->delete_multiple($keys, $group);
}

function wp_cache_flush() {
    global $wp_object_cache;
    return $wp_object_cache->flush();
}

function wp_cache_flush_runtime() {
    global $wp_object_cache;
    return $wp_object_cache->flush_runtime();
}

function wp_cache_flush_group($group) {
    global $wp_object_cache;
    return $wp_object_cache->flush_group($group);
}

function wp_cache_get($key, $group = 'default', $force = false, &$found = null) {
    global $wp_object_cache;
    return $wp_object_cache->get($key, $group, $force, $found);
}

function wp_cache_get_multiple(array $keys, $group = 'default', $force = false) {
    global $wp_object_cache;
    return $wp_object_cache->get_multiple($keys, $group, $force);
}

/**
 * Checks whether a cache key exists.
 * WordPress 6.4+
 */
function wp_cache_has($key, $group = 'default') {
    global $wp_object_cache;
    return $wp_object_cache->has($key, $group);
}

function wp_cache_incr($key, $offset = 1, $group = 'default') {
    global $wp_object_cache;
    return $wp_object_cache->incr($key, $offset, $group);
}

function wp_cache_init() {
    global $wp_object_cache;
    $wp_object_cache = new CCM_Redis_Object_Cache();
}

function wp_cache_replace($key, $data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->replace($key, $data, $group, (int) $expire);
}

function wp_cache_set($key, $data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->set($key, $data, $group, (int) $expire);
}

function wp_cache_set_multiple(array $data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->set_multiple($data, $group, (int) $expire);
}

function wp_cache_switch_to_blog($blog_id) {
    global $wp_object_cache;
    $wp_object_cache->switch_to_blog($blog_id);
}

function wp_cache_add_global_groups($groups) {
    global $wp_object_cache;
    $wp_object_cache->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups($groups) {
    global $wp_object_cache;
    $wp_object_cache->add_non_persistent_groups($groups);
}

/**
 * Reports supported features.
 */
function wp_cache_supports($feature) {
    switch ($feature) {
        case 'add_multiple':
        case 'set_multiple':
        case 'get_multiple':
        case 'delete_multiple':
        case 'flush_runtime':
        case 'flush_group':
            return true;
        default:
            return false;
    }
}

/* ────────────────────────────────────────────────────────────────
 *  Extension: wp_cache_remember / wp_cache_sear
 * ──────────────────────────────────────────────────────────────── */

/**
 * Get-or-set cache helper. If $key doesn't exist, calls $callback
 * and stores the result with the given $expire.
 *
 * @param string   $key
 * @param callable $callback
 * @param string   $group
 * @param int      $expire
 * @return mixed
 */
function wp_cache_remember($key, $callback, $group = 'default', $expire = 0) {
    $found  = false;
    $cached = wp_cache_get($key, $group, false, $found);

    if ($found) {
        return $cached;
    }

    $value = $callback();
    wp_cache_set($key, $value, $group, $expire);

    return $value;
}

/**
 * Like wp_cache_remember() but caches forever (no expiry).
 */
function wp_cache_sear($key, $callback, $group = 'default') {
    return wp_cache_remember($key, $callback, $group, 0);
}

/* ────────────────────────────────────────────────────────────────
 *  CCM Redis Object Cache Class
 * ──────────────────────────────────────────────────────────────── */
class CCM_Redis_Object_Cache {

    /** @var Redis|null */
    private $redis = null;

    /** @var bool */
    private $redis_connected = false;

    /** @var array In-memory (local) cache */
    private $cache = [];

    /** @var array Global groups (shared across multisite) */
    private $global_groups = [];

    /** @var array Non-persistent groups (memory only) */
    private $non_persistent_groups = [];

    /** @var array Ignored groups (never persisted to Redis) */
    private $ignored_groups = [];

    /** @var string Blog prefix for multisite */
    private $blog_prefix = '';

    /** @var string Cache key salt/prefix */
    private $key_salt = '';

    /** @var int Maximum TTL */
    private $max_ttl = 0;

    /** @var bool Use UNLINK/FLUSHDB ASYNC */
    private $async_flush = false;

    /** @var bool Selective flush (only site keys) */
    private $selective_flush = true;

    /** @var array Hit/miss/call/time counters */
    private $stats = [
        'hits'         => 0,
        'misses'       => 0,
        'calls'        => 0,
        'redis_calls'  => 0,
        'redis_time'   => 0.0,
    ];

    /** @var array Runtime error log */
    private $errors = [];

    /** @var array Flush log for current request */
    private $flush_log = [];

    /** @var int Max connection retries */
    private $retries = 3;

    /** @var int Retry interval in ms */
    private $retry_interval = 100;

    /** @var float Read timeout (seconds) */
    private $read_timeout = 1.0;

    /** @var bool Whether the HTML footnote is enabled */
    private $footnote_enabled = false;

    /** @var string Configured serializer name */
    private $serializer = 'php';

    /** @var string Configured compression name */
    private $compression = 'none';

    /** @var bool Whether Redis 6.0+ KEEPTTL is available */
    private $supports_keepttl = false;

    /* ───── Constructor ───── */

    public function __construct() {
        $this->key_salt = defined('WP_CACHE_KEY_SALT') ? WP_CACHE_KEY_SALT : '';

        global $blog_id;
        $this->blog_prefix = is_multisite() ? (int) $blog_id . ':' : '';

        $this->load_config();
        $this->connect();

        // Register shutdown for HTML footnote
        if ($this->footnote_enabled && function_exists('add_action')) {
            add_action('shutdown', [$this, 'output_footnote'], 0);
        }
    }

    /* ───── Configuration ───── */

    private function load_config() {
        // Global groups
        if (defined('WP_REDIS_GLOBAL_GROUPS') && is_array(WP_REDIS_GLOBAL_GROUPS)) {
            $this->global_groups = WP_REDIS_GLOBAL_GROUPS;
        } else {
            $this->global_groups = [
                'blog-details', 'blog-id-cache', 'blog-lookup', 'global-posts',
                'networks', 'rss', 'sites', 'site-details', 'site-lookup',
                'site-options', 'site-transient', 'users', 'useremail', 'userlogins',
                'usermeta', 'user_meta', 'userslugs',
            ];
        }

        // Non-persistent groups
        if (defined('WP_REDIS_NON_PERSISTENT_GROUPS') && is_array(WP_REDIS_NON_PERSISTENT_GROUPS)) {
            $this->non_persistent_groups = WP_REDIS_NON_PERSISTENT_GROUPS;
        } else {
            $this->non_persistent_groups = ['counts', 'plugins'];
        }

        // Ignored groups
        if (defined('WP_REDIS_IGNORED_GROUPS') && is_array(WP_REDIS_IGNORED_GROUPS)) {
            $this->ignored_groups = WP_REDIS_IGNORED_GROUPS;
        } else {
            $this->ignored_groups = ['counts', 'plugins', 'themes'];
        }

        // Max TTL
        if (defined('WP_REDIS_MAXTTL')) {
            $this->max_ttl = (int) WP_REDIS_MAXTTL;
        }

        // Selective flush
        if (defined('WP_REDIS_SELECTIVE_FLUSH')) {
            $this->selective_flush = (bool) WP_REDIS_SELECTIVE_FLUSH;
        }

        // Async flush
        if (defined('WP_REDIS_ASYNC_FLUSH')) {
            $this->async_flush = (bool) WP_REDIS_ASYNC_FLUSH;
        }

        // Serializer
        if (defined('WP_REDIS_SERIALIZER')) {
            $this->serializer = strtolower(WP_REDIS_SERIALIZER);
        }

        // Compression
        if (defined('WP_REDIS_COMPRESSION')) {
            $this->compression = strtolower(WP_REDIS_COMPRESSION);
        }

        // Retry settings
        if (defined('WP_REDIS_RETRY_INTERVAL')) {
            $this->retry_interval = max(0, (int) WP_REDIS_RETRY_INTERVAL);
        }
        if (defined('WP_REDIS_RETRIES')) {
            $this->retries = max(1, (int) WP_REDIS_RETRIES);
        }

        // Read timeout
        if (defined('WP_REDIS_READ_TIMEOUT')) {
            $this->read_timeout = max(0.1, (float) WP_REDIS_READ_TIMEOUT);
        }

        // Footnote (HTML comment with stats)
        if (defined('WP_REDIS_DISABLE_COMMENT') && WP_REDIS_DISABLE_COMMENT) {
            $this->footnote_enabled = false;
        } else {
            $this->footnote_enabled = true;
        }
    }

    /* ───── Connection ───── */

    private function connect() {
        if ($this->redis_connected) {
            return true;
        }

        if (!class_exists('Redis')) {
            return false;
        }

        $host         = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
        $port         = defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : 6379;
        $timeout      = defined('WP_REDIS_TIMEOUT') ? (float) WP_REDIS_TIMEOUT : 1.0;
        $read_timeout = $this->read_timeout;
        $password     = defined('WP_REDIS_PASSWORD') ? WP_REDIS_PASSWORD : '';
        $username     = defined('WP_REDIS_USERNAME') ? WP_REDIS_USERNAME : '';
        $database     = defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : 0;
        $scheme       = defined('WP_REDIS_SCHEME') ? WP_REDIS_SCHEME : 'tcp';
        $socket       = defined('WP_REDIS_PATH') ? WP_REDIS_PATH : '';

        $attempts = 0;

        while ($attempts < $this->retries) {
            $attempts++;
            try {
                $this->redis = new Redis();

                if ($scheme === 'unix' && $socket) {
                    $connected = @$this->redis->connect($socket, 0, $timeout, null, 0, $read_timeout);
                } elseif ($scheme === 'tls') {
                    $tls_context = defined('WP_REDIS_TLS_OPTIONS') && is_array(WP_REDIS_TLS_OPTIONS)
                        ? WP_REDIS_TLS_OPTIONS
                        : [];
                    $connected = @$this->redis->connect(
                        'tls://' . $host,
                        $port,
                        $timeout,
                        null,
                        0,
                        $read_timeout,
                        ['stream' => $tls_context]
                    );
                } else {
                    $connected = @$this->redis->connect($host, $port, $timeout, null, 0, $read_timeout);
                }

                if (!$connected) {
                    throw new Exception('Connection failed');
                }

                // ACL auth (Redis 6.0+) or legacy auth
                if ($password) {
                    $auth = $username ? [$username, $password] : $password;
                    if (!@$this->redis->auth($auth)) {
                        throw new Exception('Authentication failed');
                    }
                }

                // Select database
                if ($database > 0) {
                    $this->redis->select($database);
                }

                // Configure serializer
                $this->apply_serializer();

                // Configure compression
                $this->apply_compression();

                // Detect KEEPTTL support (Redis 6.0+)
                $this->detect_capabilities();

                $this->redis_connected = true;
                return true;

            } catch (Exception $e) {
                $this->redis = null;
                $this->redis_connected = false;

                // Wait before retry (decorrelated jitter)
                if ($attempts < $this->retries) {
                    $base_ms = $this->retry_interval ?: 100;
                    $jitter  = $base_ms * $attempts + random_int(0, $base_ms);
                    usleep($jitter * 1000);
                } else {
                    $this->track_error('Connection failed after ' . $attempts . ' attempts: ' . $e->getMessage());
                }
            }
        }

        return false;
    }

    /**
     * Attempt reconnection after a transient failure.
     */
    private function reconnect() {
        $this->redis_connected = false;
        $this->redis           = null;
        return $this->connect();
    }

    /**
     * Apply the configured serializer to the Redis instance.
     */
    private function apply_serializer() {
        switch ($this->serializer) {
            case 'igbinary':
                if (defined('Redis::SERIALIZER_IGBINARY')) {
                    $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
                    return;
                }
                break;
            case 'msgpack':
                if (defined('Redis::SERIALIZER_MSGPACK')) {
                    $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_MSGPACK);
                    return;
                }
                break;
        }
        // Default: PHP serializer
        $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
    }

    /**
     * Apply the configured compressor to the Redis instance.
     */
    private function apply_compression() {
        if (!defined('Redis::OPT_COMPRESSION')) {
            return;
        }

        switch ($this->compression) {
            case 'lzf':
                if (defined('Redis::COMPRESSION_LZF')) {
                    $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZF);
                    return;
                }
                break;
            case 'lz4':
                if (defined('Redis::COMPRESSION_LZ4')) {
                    $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZ4);
                    return;
                }
                break;
            case 'zstd':
                if (defined('Redis::COMPRESSION_ZSTD')) {
                    $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
                    return;
                }
                break;
        }

        $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE);
    }

    /**
     * Detect Redis server capabilities.
     */
    private function detect_capabilities() {
        try {
            $info = @$this->redis->info('server');
            if (isset($info['redis_version'])) {
                $this->supports_keepttl = version_compare($info['redis_version'], '6.0', '>=');
            }
        } catch (Exception $e) {
            // Ignore — features stay disabled
        }
    }

    /* ───── Key building ───── */

    private function build_key($key, $group = 'default') {
        if (empty($group)) {
            $group = 'default';
        }

        $prefix = $this->key_salt;

        if (!in_array($group, $this->global_groups, true)) {
            $prefix .= $this->blog_prefix;
        }

        return $prefix . $group . ':' . $key;
    }

    /* ───── Group helpers ───── */

    /**
     * Determine if a group should be persisted to Redis.
     * Supports wildcard patterns (e.g. "wc_cache_*") via fnmatch().
     */
    private function should_persist($group) {
        if (in_array($group, $this->non_persistent_groups, true) || in_array($group, $this->ignored_groups, true)) {
            return false;
        }
        // Wildcard match
        foreach ($this->non_persistent_groups as $pattern) {
            if (strpos($pattern, '*') !== false && fnmatch($pattern, $group)) {
                return false;
            }
        }
        foreach ($this->ignored_groups as $pattern) {
            if (strpos($pattern, '*') !== false && fnmatch($pattern, $group)) {
                return false;
            }
        }
        return true;
    }

    /* ───── Error tracking ───── */

    private function track_error($message) {
        global $wp_object_cache_errors;

        $entry = date('Y-m-d H:i:s') . ' ' . $message;
        $this->errors[] = $entry;

        if (is_array($wp_object_cache_errors)) {
            $wp_object_cache_errors[] = $entry;
        }

        if (function_exists('error_log')) {
            error_log('CCM Redis Object Cache: ' . $message);
        }
    }

    /* ───── Timed Redis calls ───── */

    /**
     * Execute a Redis call with timing and error handling.
     * Attempts automatic reconnection on connection-level failures.
     *
     * @param  callable $fn
     * @return mixed    Return value of $fn, or false on failure.
     */
    private function redis_call(callable $fn) {
        if (!$this->redis_connected || !$this->redis) {
            return false;
        }

        $this->stats['redis_calls']++;
        $start = microtime(true);

        try {
            $result = $fn($this->redis);
            $this->stats['redis_time'] += (microtime(true) - $start);
            return $result;
        } catch (Exception $e) {
            $this->stats['redis_time'] += (microtime(true) - $start);
            $this->track_error($e->getMessage());

            // Attempt one reconnect on connection-level failures
            if (stripos($e->getMessage(), 'went away') !== false
                || stripos($e->getMessage(), 'connection') !== false
                || stripos($e->getMessage(), 'socket') !== false
            ) {
                if ($this->reconnect()) {
                    try {
                        return $fn($this->redis);
                    } catch (Exception $e2) {
                        $this->track_error('Reconnect retry failed: ' . $e2->getMessage());
                    }
                }
            }

            return false;
        }
    }

    /* ───── add ───── */

    public function add($key, $data, $group = 'default', $expire = 0) {
        if (empty($group)) {
            $group = 'default';
        }

        if (function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition()) {
            return false;
        }

        $cache_key = $this->build_key($key, $group);

        // Already in local cache?
        if (isset($this->cache[$group][$key])) {
            return false;
        }

        // Already in Redis?
        if ($this->redis_connected && $this->should_persist($group)) {
            $exists = $this->redis_call(function ($r) use ($cache_key) {
                return $r->exists($cache_key);
            });
            if ($exists) {
                return false;
            }
        }

        return $this->set($key, $data, $group, $expire);
    }

    /* ───── add_multiple (pipelined) ───── */

    public function add_multiple(array $data, $group = 'default', $expire = 0) {
        if (empty($group)) {
            $group = 'default';
        }

        $results = [];

        // For non-persistent groups, just loop
        if (!$this->redis_connected || !$this->should_persist($group)) {
            foreach ($data as $key => $value) {
                $results[$key] = $this->add($key, $value, $group, $expire);
            }
            return $results;
        }

        // Check existence via pipeline
        $cache_keys = [];
        foreach ($data as $key => $value) {
            if (isset($this->cache[$group][$key])) {
                $results[$key] = false; // already in local cache
            } else {
                $cache_keys[$key] = $this->build_key($key, $group);
            }
        }

        if (empty($cache_keys)) {
            return $results;
        }

        // Pipeline EXISTS checks
        $existence = $this->redis_call(function ($r) use ($cache_keys) {
            $pipe = $r->pipeline();
            foreach ($cache_keys as $ck) {
                $pipe->exists($ck);
            }
            return $pipe->exec();
        });

        $i = 0;
        foreach ($cache_keys as $key => $ck) {
            if (is_array($existence) && !empty($existence[$i])) {
                $results[$key] = false;
            } else {
                $results[$key] = $this->set($key, $data[$key], $group, $expire);
            }
            $i++;
        }

        return $results;
    }

    /* ───── get ───── */

    public function get($key, $group = 'default', $force = false, &$found = null) {
        if (empty($group)) {
            $group = 'default';
        }

        $this->stats['calls']++;

        // Local cache first (unless forcing)
        if (!$force && isset($this->cache[$group][$key])) {
            $found = true;
            $this->stats['hits']++;
            $data = $this->cache[$group][$key];
            return is_object($data) ? clone $data : $data;
        }

        // Try Redis
        if ($this->redis_connected && $this->should_persist($group)) {
            $cache_key = $this->build_key($key, $group);
            $value = $this->redis_call(function ($r) use ($cache_key) {
                return $r->get($cache_key);
            });

            if ($value !== false) {
                $found = true;
                $this->stats['hits']++;
                $this->cache[$group][$key] = $value;
                return is_object($value) ? clone $value : $value;
            }
        }

        $found = false;
        $this->stats['misses']++;
        return false;
    }

    /* ───── get_multiple (pipelined mGet) ───── */

    public function get_multiple(array $keys, $group = 'default', $force = false) {
        if (empty($group)) {
            $group = 'default';
        }

        $results    = [];
        $fetch_keys = [];

        foreach ($keys as $key) {
            $this->stats['calls']++;
            if (!$force && isset($this->cache[$group][$key])) {
                $this->stats['hits']++;
                $data = $this->cache[$group][$key];
                $results[$key] = is_object($data) ? clone $data : $data;
            } else {
                $fetch_keys[$key] = $this->build_key($key, $group);
            }
        }

        if (!empty($fetch_keys) && $this->redis_connected && $this->should_persist($group)) {
            $values = $this->redis_call(function ($r) use ($fetch_keys) {
                return $r->mGet(array_values($fetch_keys));
            });

            $i = 0;
            foreach (array_keys($fetch_keys) as $key) {
                if (is_array($values) && $values[$i] !== false) {
                    $this->stats['hits']++;
                    $this->cache[$group][$key] = $values[$i];
                    $results[$key] = is_object($values[$i]) ? clone $values[$i] : $values[$i];
                } else {
                    $this->stats['misses']++;
                    $results[$key] = false;
                }
                $i++;
            }
        } else {
            foreach (array_keys($fetch_keys) as $key) {
                $this->stats['misses']++;
                $results[$key] = false;
            }
        }

        return $results;
    }

    /* ───── has (WordPress 6.4+) ───── */

    public function has($key, $group = 'default') {
        if (empty($group)) {
            $group = 'default';
        }

        if (isset($this->cache[$group][$key])) {
            return true;
        }

        if ($this->redis_connected && $this->should_persist($group)) {
            $cache_key = $this->build_key($key, $group);
            return (bool) $this->redis_call(function ($r) use ($cache_key) {
                return $r->exists($cache_key);
            });
        }

        return false;
    }

    /* ───── set ───── */

    public function set($key, $data, $group = 'default', $expire = 0) {
        if (empty($group)) {
            $group = 'default';
        }

        // Clone objects to prevent reference issues
        if (is_object($data)) {
            $data = clone $data;
        }

        // Store in local cache
        $this->cache[$group][$key] = $data;

        if ($this->redis_connected && $this->should_persist($group)) {
            $cache_key = $this->build_key($key, $group);

            // Enforce max TTL
            if ($this->max_ttl > 0 && ($expire === 0 || $expire > $this->max_ttl)) {
                $expire = $this->max_ttl;
            }

            return (bool) $this->redis_call(function ($r) use ($cache_key, $data, $expire) {
                if ($expire > 0) {
                    return $r->setex($cache_key, $expire, $data);
                }
                return $r->set($cache_key, $data);
            });
        }

        return true;
    }

    /* ───── set_multiple (pipelined) ───── */

    public function set_multiple(array $data, $group = 'default', $expire = 0) {
        if (empty($group)) {
            $group = 'default';
        }

        // Non-persistent groups — just loop locally
        if (!$this->redis_connected || !$this->should_persist($group)) {
            $results = [];
            foreach ($data as $key => $value) {
                $results[$key] = $this->set($key, $value, $group, $expire);
            }
            return $results;
        }

        $ttl = $expire;
        if ($this->max_ttl > 0 && ($ttl === 0 || $ttl > $this->max_ttl)) {
            $ttl = $this->max_ttl;
        }

        // Store locally first
        foreach ($data as $key => $value) {
            $this->cache[$group][$key] = is_object($value) ? clone $value : $value;
        }

        // Pipeline Redis writes
        $result = $this->redis_call(function ($r) use ($data, $group, $ttl) {
            $pipe = $r->pipeline();
            foreach ($data as $key => $value) {
                $cache_key = $this->build_key($key, $group);
                if (is_object($value)) {
                    $value = clone $value;
                }
                if ($ttl > 0) {
                    $pipe->setex($cache_key, $ttl, $value);
                } else {
                    $pipe->set($cache_key, $value);
                }
            }
            return $pipe->exec();
        });

        $results = [];
        $i = 0;
        foreach (array_keys($data) as $key) {
            $results[$key] = is_array($result) && !empty($result[$i]);
            $i++;
        }
        return $results;
    }

    /* ───── replace ───── */

    public function replace($key, $data, $group = 'default', $expire = 0) {
        if (empty($group)) {
            $group = 'default';
        }

        if (!isset($this->cache[$group][$key])) {
            if ($this->redis_connected && $this->should_persist($group)) {
                $cache_key = $this->build_key($key, $group);
                $exists = $this->redis_call(function ($r) use ($cache_key) {
                    return $r->exists($cache_key);
                });
                if (!$exists) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return $this->set($key, $data, $group, $expire);
    }

    /* ───── delete (supports UNLINK) ───── */

    public function delete($key, $group = 'default') {
        if (empty($group)) {
            $group = 'default';
        }

        unset($this->cache[$group][$key]);

        if ($this->redis_connected && $this->should_persist($group)) {
            $cache_key = $this->build_key($key, $group);
            return (bool) $this->redis_call(function ($r) use ($cache_key) {
                return $this->async_flush ? $r->unlink($cache_key) : $r->del($cache_key);
            });
        }

        return true;
    }

    /* ───── delete_multiple (pipelined) ───── */

    public function delete_multiple(array $keys, $group = 'default') {
        if (empty($group)) {
            $group = 'default';
        }

        // Remove from local cache
        foreach ($keys as $key) {
            unset($this->cache[$group][$key]);
        }

        if (!$this->redis_connected || !$this->should_persist($group)) {
            return array_fill_keys($keys, true);
        }

        $cache_keys = [];
        foreach ($keys as $key) {
            $cache_keys[$key] = $this->build_key($key, $group);
        }

        $result = $this->redis_call(function ($r) use ($cache_keys) {
            $pipe = $r->pipeline();
            foreach ($cache_keys as $ck) {
                $this->async_flush ? $pipe->unlink($ck) : $pipe->del($ck);
            }
            return $pipe->exec();
        });

        $results = [];
        $i = 0;
        foreach ($keys as $key) {
            $results[$key] = is_array($result) ? (bool) ($result[$i] ?? false) : false;
            $i++;
        }
        return $results;
    }

    /* ───── incr / decr ───── */

    public function incr($key, $offset = 1, $group = 'default') {
        if (empty($group)) {
            $group = 'default';
        }

        $value = $this->get($key, $group);
        if ($value === false) {
            return false;
        }

        $value = is_numeric($value) ? (int) $value : 0;
        $new_value = max(0, $value + (int) $offset);

        $this->cache[$group][$key] = $new_value;

        if ($this->redis_connected && $this->should_persist($group)) {
            $cache_key = $this->build_key($key, $group);

            $result = $this->redis_call(function ($r) use ($cache_key, $offset) {
                return $r->incrBy($cache_key, (int) $offset);
            });

            if ($result !== false) {
                // Ensure non-negative
                if ($result < 0) {
                    $this->redis_call(function ($r) use ($cache_key) {
                        return $r->set($cache_key, 0);
                    });
                    $result = 0;
                }
                $this->cache[$group][$key] = $result;
                return $result;
            }
        }

        return $new_value;
    }

    public function decr($key, $offset = 1, $group = 'default') {
        return $this->incr($key, -(int) $offset, $group);
    }

    /* ───── flush (SCAN-based, safe for production) ───── */

    public function flush() {
        $this->cache = [];

        $this->flush_log[] = [
            'type'  => 'full',
            'time'  => microtime(true),
            'trace' => function_exists('wp_debug_backtrace_summary')
                ? wp_debug_backtrace_summary(null, 0, false)
                : debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10),
        ];

        if (!$this->redis_connected) {
            return true;
        }

        return (bool) $this->redis_call(function ($r) {
            if ($this->selective_flush && !empty($this->key_salt)) {
                // SCAN-based selective flush (non-blocking)
                return $this->scan_delete($this->key_salt . '*');
            }

            // Full database flush
            if ($this->async_flush) {
                return $r->rawCommand('FLUSHDB', 'ASYNC');
            }
            return $r->flushDb();
        });
    }

    /* ───── flush_runtime ───── */

    public function flush_runtime() {
        $this->cache = [];
        return true;
    }

    /* ───── flush_group (SCAN-based) ───── */

    public function flush_group($group) {
        unset($this->cache[$group]);

        $this->flush_log[] = [
            'type'  => 'group',
            'group' => $group,
            'time'  => microtime(true),
        ];

        if ($this->redis_connected && $this->should_persist($group)) {
            $prefix = $this->key_salt;
            if (!in_array($group, $this->global_groups, true)) {
                $prefix .= $this->blog_prefix;
            }

            $pattern = $prefix . $group . ':*';

            return (bool) $this->redis_call(function () use ($pattern) {
                return $this->scan_delete($pattern);
            });
        }

        return true;
    }

    /**
     * SCAN + DEL/UNLINK in batches. Non-blocking, O(1) per iteration.
     * Must be called within a redis_call context or when Redis is confirmed connected.
     */
    private function scan_delete($pattern) {
        $deleted  = 0;
        $iterator = null;

        while (true) {
            $keys = $this->redis->scan($iterator, $pattern, 500);
            if ($keys === false || empty($keys)) {
                if ($iterator === 0 || $iterator === false) {
                    break;
                }
                continue;
            }

            if ($this->async_flush) {
                $deleted += $this->redis->unlink(...$keys);
            } else {
                $deleted += $this->redis->del(...$keys);
            }

            if ($iterator === 0) {
                break;
            }
        }

        return $deleted;
    }

    /* ───── close ───── */

    public function close() {
        if ($this->redis instanceof Redis) {
            try {
                $this->redis->close();
            } catch (Exception $e) {
                // Ignore
            }
        }

        $this->redis           = null;
        $this->redis_connected = false;

        return true;
    }

    /* ───── Multisite ───── */

    public function switch_to_blog($blog_id) {
        $this->blog_prefix = is_multisite() ? (int) $blog_id . ':' : '';
    }

    /* ───── Group management ───── */

    public function add_global_groups($groups) {
        $groups = (array) $groups;
        $this->global_groups = array_unique(array_merge($this->global_groups, $groups));
    }

    public function add_non_persistent_groups($groups) {
        $groups = (array) $groups;
        $this->non_persistent_groups = array_unique(array_merge($this->non_persistent_groups, $groups));
    }

    /* ───── Stats / Info ───── */

    /**
     * Get runtime cache statistics.
     */
    public function stats() {
        return [
            'hits'             => $this->stats['hits'],
            'misses'           => $this->stats['misses'],
            'calls'            => $this->stats['calls'],
            'redis_calls'      => $this->stats['redis_calls'],
            'redis_time_ms'    => round($this->stats['redis_time'] * 1000, 2),
            'redis_connected'  => $this->redis_connected,
            'local_cache_size' => count($this->cache, COUNT_RECURSIVE),
            'errors'           => count($this->errors),
            'serializer'       => $this->serializer,
            'compression'      => $this->compression,
        ];
    }

    public function is_connected() {
        return $this->redis_connected;
    }

    public function get_redis() {
        return $this->redis;
    }

    /**
     * Return the runtime error list.
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Return the flush log for current request.
     */
    public function get_flush_log() {
        return $this->flush_log;
    }

    /**
     * Get full info array for diagnostics.
     */
    public function info() {
        return array_merge([
            'status'           => $this->redis_connected ? 'connected' : 'disconnected',
            'serializer'       => $this->serializer,
            'compression'      => $this->compression,
            'async_flush'      => $this->async_flush,
            'selective_flush'  => $this->selective_flush,
            'max_ttl'          => $this->max_ttl,
            'key_salt'         => $this->key_salt,
            'blog_prefix'      => $this->blog_prefix,
            'supports_keepttl' => $this->supports_keepttl,
            'global_groups'    => $this->global_groups,
            'non_persistent'   => $this->non_persistent_groups,
            'ignored_groups'   => $this->ignored_groups,
            'errors'           => $this->errors,
            'flush_log'        => $this->flush_log,
        ], $this->stats);
    }

    /* ───── HTML Footnote ───── */

    /**
     * Append an HTML comment with cache statistics to the page output.
     * Hooked to 'shutdown' action.
     */
    public function output_footnote() {
        if (
            (defined('DOING_CRON') && DOING_CRON) ||
            (defined('DOING_AJAX') && DOING_AJAX) ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
            (defined('WP_CLI') && WP_CLI)
        ) {
            return;
        }

        $total   = $this->stats['hits'] + $this->stats['misses'];
        $ratio   = $total > 0 ? round(($this->stats['hits'] / $total) * 100, 1) : 0;
        $time_ms = round($this->stats['redis_time'] * 1000, 2);

        printf(
            "\n<!-- CCM Redis Object Cache | hits: %d, misses: %d, ratio: %s%%, redis calls: %d, redis time: %sms -->\n",
            $this->stats['hits'],
            $this->stats['misses'],
            $ratio,
            $this->stats['redis_calls'],
            $time_ms
        );
    }
}
