<?php
/**
 * CCM Tools Redis Object Cache Drop-In
 * 
 * A high-performance Redis-based object cache for WordPress.
 * 
 * @package CCM_Tools
 * @version 7.8.1
 * 
 * Based on WordPress object cache API.
 * 
 * This file should be placed in wp-content/object-cache.php
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Initialize object cache
if (!defined('WP_CACHE_KEY_SALT')) {
    define('WP_CACHE_KEY_SALT', '');
}

/**
 * Object Cache API Functions
 */

/**
 * Adds data to the cache, if the cache key doesn't already exist.
 *
 * @param int|string $key The cache key
 * @param mixed $data The data to cache
 * @param string $group The cache group
 * @param int $expire Expiration time in seconds
 * @return bool True on success, false on failure
 */
function wp_cache_add($key, $data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->add($key, $data, $group, (int) $expire);
}

/**
 * Adds multiple values to the cache.
 *
 * @param array $data Array of keys and values
 * @param string $group Cache group
 * @param int $expire Expiration time
 * @return array Array of results
 */
function wp_cache_add_multiple(array $data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->add_multiple($data, $group, (int) $expire);
}

/**
 * Closes the cache.
 *
 * @return bool True on success, false on failure
 */
function wp_cache_close() {
    global $wp_object_cache;
    return $wp_object_cache->close();
}

/**
 * Decrements a numeric cache item.
 *
 * @param int|string $key The cache key
 * @param int $offset Amount to decrement
 * @param string $group The cache group
 * @return int|false The new value on success, false on failure
 */
function wp_cache_decr($key, $offset = 1, $group = 'default') {
    global $wp_object_cache;
    return $wp_object_cache->decr($key, $offset, $group);
}

/**
 * Removes a cache item.
 *
 * @param int|string $key The cache key
 * @param string $group The cache group
 * @return bool True on success, false on failure
 */
function wp_cache_delete($key, $group = 'default') {
    global $wp_object_cache;
    return $wp_object_cache->delete($key, $group);
}

/**
 * Deletes multiple values from the cache.
 *
 * @param array $keys Array of keys
 * @param string $group Cache group
 * @return array Array of results
 */
function wp_cache_delete_multiple(array $keys, $group = 'default') {
    global $wp_object_cache;
    return $wp_object_cache->delete_multiple($keys, $group);
}

/**
 * Flushes the cache.
 *
 * @return bool True on success, false on failure
 */
function wp_cache_flush() {
    global $wp_object_cache;
    return $wp_object_cache->flush();
}

/**
 * Flushes runtime cache (non-persistent).
 *
 * @return bool True on success
 */
function wp_cache_flush_runtime() {
    global $wp_object_cache;
    return $wp_object_cache->flush_runtime();
}

/**
 * Retrieves data from the cache.
 *
 * @param int|string $key The cache key
 * @param string $group The cache group
 * @param bool $force Whether to force a fetch from Redis
 * @param bool &$found Whether the key was found
 * @return mixed|false Cache data on success, false on failure
 */
function wp_cache_get($key, $group = 'default', $force = false, &$found = null) {
    global $wp_object_cache;
    return $wp_object_cache->get($key, $group, $force, $found);
}

/**
 * Gets multiple values from the cache.
 *
 * @param array $keys Array of keys
 * @param string $group Cache group
 * @param bool $force Whether to force a fetch
 * @return array Array of values
 */
function wp_cache_get_multiple(array $keys, $group = 'default', $force = false) {
    global $wp_object_cache;
    return $wp_object_cache->get_multiple($keys, $group, $force);
}

/**
 * Increments a numeric cache item.
 *
 * @param int|string $key The cache key
 * @param int $offset Amount to increment
 * @param string $group The cache group
 * @return int|false The new value on success, false on failure
 */
function wp_cache_incr($key, $offset = 1, $group = 'default') {
    global $wp_object_cache;
    return $wp_object_cache->incr($key, $offset, $group);
}

/**
 * Initializes the cache.
 */
function wp_cache_init() {
    global $wp_object_cache;
    $wp_object_cache = new CCM_Redis_Object_Cache();
}

/**
 * Replaces a cache item.
 *
 * @param int|string $key The cache key
 * @param mixed $data The data to cache
 * @param string $group The cache group
 * @param int $expire Expiration time
 * @return bool True on success, false on failure
 */
function wp_cache_replace($key, $data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->replace($key, $data, $group, (int) $expire);
}

/**
 * Sets a cache item.
 *
 * @param int|string $key The cache key
 * @param mixed $data The data to cache
 * @param string $group The cache group
 * @param int $expire Expiration time
 * @return bool True on success, false on failure
 */
function wp_cache_set($key, $data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->set($key, $data, $group, (int) $expire);
}

/**
 * Sets multiple values in the cache.
 *
 * @param array $data Array of keys and values
 * @param string $group Cache group
 * @param int $expire Expiration time
 * @return array Array of results
 */
function wp_cache_set_multiple(array $data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->set_multiple($data, $group, (int) $expire);
}

/**
 * Switches the internal blog ID.
 *
 * @param int $blog_id Blog ID
 */
function wp_cache_switch_to_blog($blog_id) {
    global $wp_object_cache;
    $wp_object_cache->switch_to_blog($blog_id);
}

/**
 * Adds a group to the global cache groups.
 *
 * @param string|array $groups Groups to add
 */
function wp_cache_add_global_groups($groups) {
    global $wp_object_cache;
    $wp_object_cache->add_global_groups($groups);
}

/**
 * Adds a group to the non-persistent cache groups.
 *
 * @param string|array $groups Groups to add
 */
function wp_cache_add_non_persistent_groups($groups) {
    global $wp_object_cache;
    $wp_object_cache->add_non_persistent_groups($groups);
}

/**
 * Determines whether the object cache implementation supports a particular feature.
 *
 * @param string $feature Name of the feature
 * @return bool True if supported
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

/**
 * Flushes a specific cache group.
 *
 * @param string $group Cache group
 * @return bool True on success
 */
function wp_cache_flush_group($group) {
    global $wp_object_cache;
    return $wp_object_cache->flush_group($group);
}

/**
 * CCM Redis Object Cache Class
 */
class CCM_Redis_Object_Cache {

    /**
     * Redis connection instance
     * @var Redis|null
     */
    private $redis = null;

    /**
     * Whether Redis is connected
     * @var bool
     */
    private $redis_connected = false;

    /**
     * Local cache for non-persistent groups and in-memory caching
     * @var array
     */
    private $cache = array();

    /**
     * Global cache groups (shared across multisite)
     * @var array
     */
    private $global_groups = array();

    /**
     * Non-persistent cache groups (memory only)
     * @var array
     */
    private $non_persistent_groups = array();

    /**
     * Ignored cache groups (never cached in Redis)
     * @var array
     */
    private $ignored_groups = array();

    /**
     * Current blog ID for multisite
     * @var int
     */
    private $blog_prefix = '';

    /**
     * Cache key salt/prefix
     * @var string
     */
    private $key_salt = '';

    /**
     * Maximum TTL for cache items
     * @var int
     */
    private $max_ttl = 0;

    /**
     * Cache statistics
     * @var array
     */
    private $stats = array(
        'hits' => 0,
        'misses' => 0,
        'calls' => 0,
    );

    /**
     * Whether to use selective flush
     * @var bool
     */
    private $selective_flush = true;

    /**
     * Constructor
     */
    public function __construct() {
        $this->key_salt = WP_CACHE_KEY_SALT;
        
        // Set blog prefix for multisite
        global $blog_id;
        $this->blog_prefix = is_multisite() ? $blog_id . ':' : '';
        
        // Load configuration
        $this->load_config();
        
        // Connect to Redis
        $this->connect();
    }

    /**
     * Load configuration from constants
     */
    private function load_config() {
        // Global groups
        if (defined('WP_REDIS_GLOBAL_GROUPS') && is_array(WP_REDIS_GLOBAL_GROUPS)) {
            $this->global_groups = WP_REDIS_GLOBAL_GROUPS;
        } else {
            $this->global_groups = array(
                'blog-details', 'blog-id-cache', 'blog-lookup', 'global-posts',
                'networks', 'rss', 'sites', 'site-details', 'site-lookup',
                'site-options', 'site-transient', 'users', 'useremail', 'userlogins',
                'usermeta', 'user_meta', 'userslugs'
            );
        }
        
        // Non-persistent groups
        if (defined('WP_REDIS_NON_PERSISTENT_GROUPS') && is_array(WP_REDIS_NON_PERSISTENT_GROUPS)) {
            $this->non_persistent_groups = WP_REDIS_NON_PERSISTENT_GROUPS;
        } else {
            $this->non_persistent_groups = array('counts', 'plugins');
        }
        
        // Ignored groups
        if (defined('WP_REDIS_IGNORED_GROUPS') && is_array(WP_REDIS_IGNORED_GROUPS)) {
            $this->ignored_groups = WP_REDIS_IGNORED_GROUPS;
        } else {
            $this->ignored_groups = array('counts', 'plugins', 'themes');
        }
        
        // Max TTL
        if (defined('WP_REDIS_MAXTTL')) {
            $this->max_ttl = intval(WP_REDIS_MAXTTL);
        }
        
        // Selective flush
        if (defined('WP_REDIS_SELECTIVE_FLUSH')) {
            $this->selective_flush = (bool) WP_REDIS_SELECTIVE_FLUSH;
        }
    }

    /**
     * Connect to Redis
     *
     * @return bool Success
     */
    private function connect() {
        if ($this->redis_connected) {
            return true;
        }
        
        if (!class_exists('Redis')) {
            return false;
        }
        
        try {
            $this->redis = new Redis();
            
            // Get connection parameters
            $host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
            $port = defined('WP_REDIS_PORT') ? intval(WP_REDIS_PORT) : 6379;
            $timeout = defined('WP_REDIS_TIMEOUT') ? floatval(WP_REDIS_TIMEOUT) : 1.0;
            $read_timeout = defined('WP_REDIS_READ_TIMEOUT') ? floatval(WP_REDIS_READ_TIMEOUT) : 1.0;
            
            // Unix socket connection
            if (defined('WP_REDIS_PATH') && WP_REDIS_PATH) {
                $connected = @$this->redis->connect(WP_REDIS_PATH);
            } 
            // TLS connection
            elseif (defined('WP_REDIS_SCHEME') && WP_REDIS_SCHEME === 'tls') {
                $connected = @$this->redis->connect('tls://' . $host, $port, $timeout, null, 0, $read_timeout);
            }
            // Standard TCP connection
            else {
                $connected = @$this->redis->connect($host, $port, $timeout, null, 0, $read_timeout);
            }
            
            if (!$connected) {
                $this->redis = null;
                return false;
            }
            
            // Authentication
            if (defined('WP_REDIS_PASSWORD') && WP_REDIS_PASSWORD) {
                if (!@$this->redis->auth(WP_REDIS_PASSWORD)) {
                    $this->redis = null;
                    return false;
                }
            }
            
            // Select database
            if (defined('WP_REDIS_DATABASE') && WP_REDIS_DATABASE > 0) {
                $this->redis->select(intval(WP_REDIS_DATABASE));
            }
            
            // Set options
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
            
            $this->redis_connected = true;
            return true;
            
        } catch (Exception $e) {
            $this->redis = null;
            $this->redis_connected = false;
            return false;
        }
    }

    /**
     * Build a cache key
     *
     * @param string $key The key
     * @param string $group The group
     * @return string The full cache key
     */
    private function build_key($key, $group = 'default') {
        if (empty($group)) {
            $group = 'default';
        }
        
        $prefix = $this->key_salt;
        
        // Add blog prefix for non-global groups
        if (!in_array($group, $this->global_groups)) {
            $prefix .= $this->blog_prefix;
        }
        
        return $prefix . $group . ':' . $key;
    }

    /**
     * Check if a group should be persisted to Redis
     *
     * @param string $group The group
     * @return bool Whether to persist
     */
    private function should_persist($group) {
        return !in_array($group, $this->non_persistent_groups) && !in_array($group, $this->ignored_groups);
    }

    /**
     * Add data to cache if it doesn't exist
     *
     * @param string $key The key
     * @param mixed $data The data
     * @param string $group The group
     * @param int $expire Expiration
     * @return bool Success
     */
    public function add($key, $data, $group = 'default', $expire = 0) {
        if (empty($group)) {
            $group = 'default';
        }
        
        $cache_key = $this->build_key($key, $group);
        
        // Check if already exists in local cache
        if (isset($this->cache[$group][$key])) {
            return false;
        }
        
        // Check if already exists in Redis
        if ($this->redis_connected && $this->should_persist($group)) {
            if ($this->redis->exists($cache_key)) {
                return false;
            }
        }
        
        return $this->set($key, $data, $group, $expire);
    }

    /**
     * Add multiple items
     *
     * @param array $data Key => value array
     * @param string $group The group
     * @param int $expire Expiration
     * @return array Results
     */
    public function add_multiple(array $data, $group = 'default', $expire = 0) {
        $results = array();
        foreach ($data as $key => $value) {
            $results[$key] = $this->add($key, $value, $group, $expire);
        }
        return $results;
    }

    /**
     * Get data from cache
     *
     * @param string $key The key
     * @param string $group The group
     * @param bool $force Force fetch from Redis
     * @param bool &$found Whether key was found
     * @return mixed|false The data or false
     */
    public function get($key, $group = 'default', $force = false, &$found = null) {
        if (empty($group)) {
            $group = 'default';
        }
        
        $this->stats['calls']++;
        $cache_key = $this->build_key($key, $group);
        
        // Check local cache first (unless forcing)
        if (!$force && isset($this->cache[$group][$key])) {
            $found = true;
            $this->stats['hits']++;
            return is_object($this->cache[$group][$key]) ? clone $this->cache[$group][$key] : $this->cache[$group][$key];
        }
        
        // Try Redis
        if ($this->redis_connected && $this->should_persist($group)) {
            try {
                $value = $this->redis->get($cache_key);
                
                if ($value !== false) {
                    $found = true;
                    $this->stats['hits']++;
                    $this->cache[$group][$key] = $value;
                    return is_object($value) ? clone $value : $value;
                }
            } catch (Exception $e) {
                // Redis error - fall through to miss
            }
        }
        
        $found = false;
        $this->stats['misses']++;
        return false;
    }

    /**
     * Get multiple items
     *
     * @param array $keys Array of keys
     * @param string $group The group
     * @param bool $force Force fetch
     * @return array Results
     */
    public function get_multiple(array $keys, $group = 'default', $force = false) {
        $results = array();
        $fetch_keys = array();
        
        foreach ($keys as $key) {
            // Check local cache first
            if (!$force && isset($this->cache[$group][$key])) {
                $this->stats['hits']++;
                $results[$key] = is_object($this->cache[$group][$key]) ? clone $this->cache[$group][$key] : $this->cache[$group][$key];
            } else {
                $fetch_keys[$key] = $this->build_key($key, $group);
            }
        }
        
        // Fetch remaining from Redis
        if (!empty($fetch_keys) && $this->redis_connected && $this->should_persist($group)) {
            try {
                $values = $this->redis->mGet(array_values($fetch_keys));
                $i = 0;
                foreach (array_keys($fetch_keys) as $key) {
                    if ($values[$i] !== false) {
                        $this->stats['hits']++;
                        $this->cache[$group][$key] = $values[$i];
                        $results[$key] = is_object($values[$i]) ? clone $values[$i] : $values[$i];
                    } else {
                        $this->stats['misses']++;
                        $results[$key] = false;
                    }
                    $i++;
                }
            } catch (Exception $e) {
                foreach (array_keys($fetch_keys) as $key) {
                    $this->stats['misses']++;
                    $results[$key] = false;
                }
            }
        } else {
            foreach (array_keys($fetch_keys) as $key) {
                $this->stats['misses']++;
                $results[$key] = false;
            }
        }
        
        return $results;
    }

    /**
     * Set data in cache
     *
     * @param string $key The key
     * @param mixed $data The data
     * @param string $group The group
     * @param int $expire Expiration
     * @return bool Success
     */
    public function set($key, $data, $group = 'default', $expire = 0) {
        if (empty($group)) {
            $group = 'default';
        }
        
        $cache_key = $this->build_key($key, $group);
        
        // Clone objects to prevent reference issues
        if (is_object($data)) {
            $data = clone $data;
        }
        
        // Store in local cache
        $this->cache[$group][$key] = $data;
        
        // Store in Redis if persistent
        if ($this->redis_connected && $this->should_persist($group)) {
            try {
                // Apply max TTL
                if ($this->max_ttl > 0 && ($expire === 0 || $expire > $this->max_ttl)) {
                    $expire = $this->max_ttl;
                }
                
                if ($expire > 0) {
                    return $this->redis->setex($cache_key, $expire, $data);
                } else {
                    return $this->redis->set($cache_key, $data);
                }
            } catch (Exception $e) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Set multiple items
     *
     * @param array $data Key => value array
     * @param string $group The group
     * @param int $expire Expiration
     * @return array Results
     */
    public function set_multiple(array $data, $group = 'default', $expire = 0) {
        $results = array();
        foreach ($data as $key => $value) {
            $results[$key] = $this->set($key, $value, $group, $expire);
        }
        return $results;
    }

    /**
     * Replace data in cache
     *
     * @param string $key The key
     * @param mixed $data The data
     * @param string $group The group
     * @param int $expire Expiration
     * @return bool Success
     */
    public function replace($key, $data, $group = 'default', $expire = 0) {
        if (empty($group)) {
            $group = 'default';
        }
        
        $cache_key = $this->build_key($key, $group);
        
        // Check if exists
        if (!isset($this->cache[$group][$key])) {
            if ($this->redis_connected && $this->should_persist($group)) {
                if (!$this->redis->exists($cache_key)) {
                    return false;
                }
            } else {
                return false;
            }
        }
        
        return $this->set($key, $data, $group, $expire);
    }

    /**
     * Delete data from cache
     *
     * @param string $key The key
     * @param string $group The group
     * @return bool Success
     */
    public function delete($key, $group = 'default') {
        if (empty($group)) {
            $group = 'default';
        }
        
        $cache_key = $this->build_key($key, $group);
        
        // Remove from local cache
        unset($this->cache[$group][$key]);
        
        // Remove from Redis
        if ($this->redis_connected && $this->should_persist($group)) {
            try {
                $this->redis->del($cache_key);
            } catch (Exception $e) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Delete multiple items
     *
     * @param array $keys Array of keys
     * @param string $group The group
     * @return array Results
     */
    public function delete_multiple(array $keys, $group = 'default') {
        $results = array();
        foreach ($keys as $key) {
            $results[$key] = $this->delete($key, $group);
        }
        return $results;
    }

    /**
     * Increment a numeric value
     *
     * @param string $key The key
     * @param int $offset Amount to increment
     * @param string $group The group
     * @return int|false New value or false
     */
    public function incr($key, $offset = 1, $group = 'default') {
        if (empty($group)) {
            $group = 'default';
        }
        
        $cache_key = $this->build_key($key, $group);
        
        // Get current value
        $value = $this->get($key, $group);
        if ($value === false) {
            return false;
        }
        
        if (!is_numeric($value)) {
            $value = 0;
        }
        
        $value += $offset;
        
        if ($value < 0) {
            $value = 0;
        }
        
        if ($this->redis_connected && $this->should_persist($group)) {
            try {
                $this->redis->incrBy($cache_key, $offset);
            } catch (Exception $e) {
                // Fall back to set
                $this->set($key, $value, $group);
            }
        }
        
        $this->cache[$group][$key] = $value;
        
        return $value;
    }

    /**
     * Decrement a numeric value
     *
     * @param string $key The key
     * @param int $offset Amount to decrement
     * @param string $group The group
     * @return int|false New value or false
     */
    public function decr($key, $offset = 1, $group = 'default') {
        return $this->incr($key, -$offset, $group);
    }

    /**
     * Flush the entire cache
     *
     * @return bool Success
     */
    public function flush() {
        $this->cache = array();
        
        if ($this->redis_connected) {
            try {
                if ($this->selective_flush && !empty($this->key_salt)) {
                    // Selective flush - only our keys
                    $pattern = $this->key_salt . '*';
                    $keys = $this->redis->keys($pattern);
                    if (!empty($keys)) {
                        $this->redis->del($keys);
                    }
                } else {
                    // Full database flush
                    $this->redis->flushDb();
                }
            } catch (Exception $e) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Flush runtime (local) cache only
     *
     * @return bool Success
     */
    public function flush_runtime() {
        $this->cache = array();
        return true;
    }

    /**
     * Flush a specific group
     *
     * @param string $group The group
     * @return bool Success
     */
    public function flush_group($group) {
        unset($this->cache[$group]);
        
        if ($this->redis_connected && $this->should_persist($group)) {
            try {
                $prefix = $this->key_salt;
                if (!in_array($group, $this->global_groups)) {
                    $prefix .= $this->blog_prefix;
                }
                
                $pattern = $prefix . $group . ':*';
                $keys = $this->redis->keys($pattern);
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
            } catch (Exception $e) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Close the Redis connection
     *
     * @return bool Success
     */
    public function close() {
        if ($this->redis instanceof Redis) {
            try {
                $this->redis->close();
            } catch (Exception $e) {
                // Ignore close errors
            }
        }
        
        $this->redis = null;
        $this->redis_connected = false;
        
        return true;
    }

    /**
     * Switch to a different blog (multisite)
     *
     * @param int $blog_id Blog ID
     */
    public function switch_to_blog($blog_id) {
        $this->blog_prefix = is_multisite() ? $blog_id . ':' : '';
    }

    /**
     * Add global cache groups
     *
     * @param string|array $groups Groups to add
     */
    public function add_global_groups($groups) {
        $groups = (array) $groups;
        $this->global_groups = array_merge($this->global_groups, $groups);
        $this->global_groups = array_unique($this->global_groups);
    }

    /**
     * Add non-persistent cache groups
     *
     * @param string|array $groups Groups to add
     */
    public function add_non_persistent_groups($groups) {
        $groups = (array) $groups;
        $this->non_persistent_groups = array_merge($this->non_persistent_groups, $groups);
        $this->non_persistent_groups = array_unique($this->non_persistent_groups);
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics
     */
    public function stats() {
        return array(
            'hits' => $this->stats['hits'],
            'misses' => $this->stats['misses'],
            'calls' => $this->stats['calls'],
            'redis_connected' => $this->redis_connected,
            'local_cache_size' => count($this->cache, COUNT_RECURSIVE),
        );
    }

    /**
     * Check if Redis is connected
     *
     * @return bool Connected
     */
    public function is_connected() {
        return $this->redis_connected;
    }

    /**
     * Get the Redis instance
     *
     * @return Redis|null
     */
    public function get_redis() {
        return $this->redis;
    }
}
