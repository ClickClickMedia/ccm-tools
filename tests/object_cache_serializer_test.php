<?php
/**
 * A value the drop-in cannot decode must read as a cache miss, not as a string.
 *
 * A client site carried a WP_CACHE_KEY_SALT left over from Object Cache Pro
 * and WP_REDIS_SERIALIZER = 'igbinary'. Most values under that salt really
 * were igbinary, but some were still PHP-serialized from the previous cache.
 * phpredis does not raise on a value it cannot deserialize — it hands back the
 * stored bytes verbatim — so wp_cache_get() returned the literal string
 * 'a:3:{i:0;s:85:"/home/...' to callers expecting an array.
 *
 * WordPress core only treats `false` as a miss:
 *
 *     $files = wp_cache_get( $cache_key, 'translation_files' );
 *     if ( false === $files ) { $files = glob( $path . '*.mo' ); }
 *     foreach ( $files as $file_path ) { ... }
 *
 * so the string went straight into the foreach and the site logged
 * "foreach() argument must be of type array|object, string given" on every
 * request. Same family as the LZ4+igbinary corruption that OOM'd
 * thesportingbase.com, and on a larger value it ends the same way.
 *
 * The hard part is not spotting 'a:3:{' — it is spotting it without breaking
 * the site that honestly caches the string 'a:3:{i:0;s:3:"abc";}', which comes
 * back from a *successful* igbinary round-trip looking exactly the same. So
 * this drives the real class through a phpredis stand-in that reproduces the
 * documented behaviour, and checks both directions: the poisoned value is
 * dropped, and the honest one is not.
 *
 * Run:  php tests/object_cache_serializer_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// ── A small WordPress ────────────────────────────────────────────

define('ABSPATH', $root . '/');
define('WP_CACHE_KEY_SALT', 'ocp_c3b1e7:');
define('WP_REDIS_SERIALIZER', 'igbinary');

function is_multisite() { return false; }
function add_action($h, $c, $p = 10, $a = 1) { return true; }

// track_error() calls error_log() directly, which would otherwise scribble
// over the test output. The messages are asserted via $wp_object_cache_errors.
$ccm_log = tempnam(sys_get_temp_dir(), 'ccm_oc_');
ini_set('error_log', $ccm_log);

// ── phpredis, as far as this test needs it ───────────────────────

/**
 * The one behaviour that matters is in get(): with OPT_SERIALIZER set to
 * igbinary, bytes that are not igbinary come back untouched rather than as an
 * error. That is the production fault, reproduced here so the fix is tested
 * against it rather than against a description of it.
 */
class Redis {
    const OPT_SERIALIZER      = 1;
    const OPT_COMPRESSION     = 7;
    const SERIALIZER_PHP      = 1;
    const SERIALIZER_IGBINARY = 2;
    const COMPRESSION_NONE    = 0;

    /** @var array Raw bytes, exactly as they sit in Redis. */
    public static $store = array();

    /** @var int What phpredis has been told to deserialize with. */
    public static $serializer = self::SERIALIZER_PHP;

    /** @var array Keys passed to DEL/UNLINK. */
    public static $deleted = array();

    /** @var int Raw GETs issued by the guard. */
    public static $raw_gets = 0;

    /**
     * Stand-in for the igbinary wire format.
     *
     * Only the four-byte version header is load-bearing — it is the sole part
     * the drop-in inspects — so the payload stays as serialize() and this runs
     * on a PHP without the igbinary extension.
     */
    public static function igbinary($value) {
        return "\x00\x00\x00\x02" . serialize($value);
    }

    public function connect($host, $port = 6379, $timeout = 0, $reserved = null, $retry = 0, $read_timeout = 0, $context = array()) {
        return true;
    }

    public function auth($credentials) { return true; }
    public function select($db) { return true; }
    public function close() { return true; }
    public function ping() { return true; }

    public function setOption($option, $value) {
        if ($option === self::OPT_SERIALIZER) {
            self::$serializer = (int) $value;
        }
        return true;
    }

    public function info($section = null) {
        return array('redis_version' => '7.0.0');
    }

    public function get($key) {
        if (!isset(self::$store[$key])) {
            return false;
        }

        $raw = self::$store[$key];

        if (self::$serializer === self::SERIALIZER_IGBINARY) {
            if (strncmp($raw, "\x00\x00\x00\x02", 4) !== 0) {
                return $raw; // the bug: undecodable bytes, returned as-is
            }
            return unserialize(substr($raw, 4));
        }

        $decoded = @unserialize($raw);
        if ($decoded === false && $raw !== serialize(false)) {
            return $raw; // same failure mode, PHP serializer, igbinary bytes
        }
        return $decoded;
    }

    public function mGet(array $keys) {
        $out = array();
        foreach ($keys as $key) {
            $out[] = $this->get($key);
        }
        return $out;
    }

    public function exists($key) {
        return isset(self::$store[$key]) ? 1 : 0;
    }

    public function del($key) {
        self::$deleted[] = $key;
        unset(self::$store[$key]);
        return 1;
    }

    public function unlink($key) {
        return $this->del($key);
    }

    /**
     * Bypasses OPT_SERIALIZER, the way the real one does — which is the whole
     * reason the drop-in can use it to tell raw bytes from a decoded string.
     */
    public function rawCommand($command, $key, $value = null) {
        if (strtoupper($command) === 'SET') {
            self::$store[$key] = $value;
            return true;
        }
        self::$raw_gets++;
        return isset(self::$store[$key]) ? self::$store[$key] : false;
    }
}

require $root . '/assets/object-cache.php';

// ── Harness ──────────────────────────────────────────────────────

$failures = 0;

function check(string $label, bool $pass, string $detail = ''): void {
    global $failures;
    if ($pass) {
        printf("  ok   %s\n", $label);
        return;
    }
    $failures++;
    printf("  FAIL %-58s %s\n", $label, $detail);
}

$build_key = new ReflectionMethod('CCM_Redis_Object_Cache', 'build_key');
$build_key->setAccessible(true);

/** Put raw bytes under the key the drop-in will look for. */
function seed($cache, string $key, string $group, string $raw): string {
    global $build_key;
    $redis_key = $build_key->invoke($cache, $key, $group);
    Redis::$store[$redis_key] = $raw;
    return $redis_key;
}

function describe($value): string {
    if (is_string($value)) {
        return "string('" . (strlen($value) > 34 ? substr($value, 0, 34) . '...' : $value) . "')";
    }
    return gettype($value) . '(' . var_export($value, true) . ')';
}

printf("Redis drop-in: undecodable values must read as a miss\n\n");

$group = 'translation_files';
$cache = new CCM_Redis_Object_Cache();

check(
    'the stand-in was configured for igbinary',
    Redis::$serializer === Redis::SERIALIZER_IGBINARY,
    'serializer option is ' . Redis::$serializer . ', so nothing below tests the igbinary path'
);

// ── The bug ──────────────────────────────────────────────────────

// The exact shape read out of the live instance: a PHP-serialized list of .mo
// paths sitting under an igbinary salt.
$poison = 'a:3:{i:0;s:85:"/home/cpnclickmedia/public_html/wp-content/languages/plugins/woocommerce-en_AU.mo";i:1;s:40:"/home/cpnclickmedia/public_html/x-en_AU.mo";i:2;s:40:"/home/cpnclickmedia/public_html/y-en_AU.mo";}';
$poison_key = seed($cache, 'wp-content/languages/plugins/', $group, $poison);

$before = $cache->stats();
$found  = null;
$value  = $cache->get('wp-content/languages/plugins/', $group, false, $found);
$after  = $cache->stats();

check(
    'a PHP-serialized value under igbinary returns false, not a string',
    $value === false,
    'returned ' . describe($value) . ' — core would foreach() over this'
);
check(
    'and reports itself as not found',
    $found === false,
    '$found came back ' . var_export($found, true)
);
check(
    'and counts as a miss, not a hit',
    $after['misses'] === $before['misses'] + 1 && $after['hits'] === $before['hits'],
    sprintf('hits %d->%d, misses %d->%d', $before['hits'], $after['hits'], $before['misses'], $after['misses'])
);
check(
    'and drops the poisoned key so it stops costing a round trip',
    in_array($poison_key, Redis::$deleted, true) && !isset(Redis::$store[$poison_key]),
    'the key is still there; the next request pays for it again'
);
check(
    'and says so in the error log',
    count($GLOBALS['wp_object_cache_errors']) > 0
        && strpos(end($GLOBALS['wp_object_cache_errors']), $group) !== false,
    'nothing was recorded, so this would heal silently and never get diagnosed'
);

// ── The cases that must keep working ─────────────────────────────

seed($cache, 'real-array', $group, Redis::igbinary(array('a', 'b', 'c')));
check(
    'a genuinely cached array still round-trips',
    $cache->get('real-array', $group) === array('a', 'b', 'c'),
    'returned ' . describe($cache->get('real-array', $group))
);

seed($cache, 'real-string', $group, Redis::igbinary('just a string'));
check(
    'a genuinely cached string still round-trips',
    $cache->get('real-string', $group) === 'just a string',
    'returned ' . describe($cache->get('real-string', $group))
);

// The false positive the shape check alone would cause. This string IS a
// complete, valid PHP serialization — it is also exactly what the site asked
// us to cache, stored correctly as igbinary. Discarding it would thrash the
// key forever: regenerate, store, flag, delete, repeat.
$honest = 'a:3:{i:0;s:3:"abc";i:1;s:3:"def";i:2;s:3:"ghi";}';
$honest_key = seed($cache, 'string-that-looks-serialized', $group, Redis::igbinary($honest));
$found = null;
check(
    'a real string that merely looks PHP-serialized is NOT discarded',
    $cache->get('string-that-looks-serialized', $group, false, $found) === $honest && $found === true,
    'the guard fired on an honest value; that key would never cache again'
);
check(
    'and is left in Redis',
    isset(Redis::$store[$honest_key]) && !in_array($honest_key, Redis::$deleted, true),
    'an honest value was deleted'
);

seed($cache, 'empty', $group, Redis::igbinary(''));
$found = null;
check(
    'an empty string is a hit, not a miss',
    $cache->get('empty', $group, false, $found) === '' && $found === true,
    'returned ' . describe($cache->get('empty', $group)) . ', found=' . var_export($found, true)
);

$found = null;
check(
    'an absent key is still a plain miss',
    $cache->get('never-written', $group, false, $found) === false && $found === false,
    'an absent key stopped reading as a miss'
);

seed($cache, 'stored-false', $group, Redis::igbinary(false));
check(
    'a stored false is indistinguishable from a miss, as before',
    $cache->get('stored-false', $group) === false,
    'behaviour changed for a stored false'
);

// ── get_multiple takes the same route ────────────────────────────

$cache->flush_runtime();
Redis::$deleted = array();

$mixed_poison = 'a:1:{i:0;s:11:"/x/en_AU.mo";}';
$mg_poison_key = seed($cache, 'mg-poison', $group, $mixed_poison);
seed($cache, 'mg-good', $group, Redis::igbinary(array('fine')));
seed($cache, 'mg-honest', $group, Redis::igbinary('a:1:{i:0;s:1:"z";}'));

$before = $cache->stats();
$multi  = $cache->get_multiple(array('mg-poison', 'mg-good', 'mg-honest'), $group);
$after  = $cache->stats();

check(
    'get_multiple returns false for the undecodable member',
    $multi['mg-poison'] === false,
    'returned ' . describe($multi['mg-poison']) . ' — mGet decodes the same way get() does'
);
check(
    'get_multiple keeps the decodable members',
    $multi['mg-good'] === array('fine') && $multi['mg-honest'] === 'a:1:{i:0;s:1:"z";}',
    'a good value was lost: ' . describe($multi['mg-good']) . ' / ' . describe($multi['mg-honest'])
);
check(
    'get_multiple counts one miss and two hits',
    $after['misses'] === $before['misses'] + 1 && $after['hits'] === $before['hits'] + 2,
    sprintf('hits +%d, misses +%d', $after['hits'] - $before['hits'], $after['misses'] - $before['misses'])
);
check(
    'get_multiple drops the poisoned key too',
    in_array($mg_poison_key, Redis::$deleted, true),
    'the key survives, so every request repeats the work'
);

// ── The mirror image: igbinary bytes under the PHP serializer ────

// Same fault the other way round — an igbinary value left behind on a site
// that has since lost the extension. Fresh instance, both sides switched.
Redis::$serializer = Redis::SERIALIZER_PHP;
$php_cache = new CCM_Redis_Object_Cache();

$serializer_prop = new ReflectionProperty('CCM_Redis_Object_Cache', 'serializer');
$serializer_prop->setAccessible(true);
$serializer_prop->setValue($php_cache, 'php');
Redis::$serializer = Redis::SERIALIZER_PHP;

seed($php_cache, 'mirror-poison', $group, Redis::igbinary(array('q')));
check(
    'igbinary bytes read under the PHP serializer are a miss too',
    $php_cache->get('mirror-poison', $group) === false,
    'returned ' . describe($php_cache->get('mirror-poison', $group))
);

seed($php_cache, 'mirror-good', $group, serialize(array('r')));
check(
    'and a correctly PHP-serialized value is untouched',
    $php_cache->get('mirror-good', $group) === array('r'),
    'the guard fired on a correctly stored value'
);

// ── The guard must not cost a round trip on ordinary values ──────

$cache->flush_runtime();
seed($cache, 'ordinary', $group, Redis::igbinary(array(1, 2, 3)));
$raw_before = Redis::$raw_gets;
$cache->get('ordinary', $group);
check(
    'an ordinary value is settled without a second read',
    Redis::$raw_gets === $raw_before,
    sprintf('%d extra raw GET(s) on a value that never looked suspect', Redis::$raw_gets - $raw_before)
);

@unlink($ccm_log);

printf("\n");
if ($failures) {
    printf("%d check(s) failed\n", $failures);
    exit(1);
}
printf("undecodable values read as misses and are dropped, and every value that\n");
printf("decoded correctly — arrays, strings, '' and strings that look serialized — survives\n");
