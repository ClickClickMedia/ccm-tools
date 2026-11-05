<?php
/**
 * Cloudflare auto-purge: does saving content purge the URLs that were live?
 *
 *     php tests/cf_auto_purge_test.php
 *
 * Drives the REAL hook functions in inc/cloudflare.php through a small
 * WordPress simulator that fires hooks in the same order core does. The order
 * is the whole point, so it was copied from wp-includes/post.php and the REST
 * posts controller rather than remembered:
 *
 *  - wp_insert_post() captures $post_before, THEN writes the `__trashed` slug
 *    straight to the database when binning, THEN fires pre_post_update, THEN
 *    assigns the default category if none was given, THEN save_post, THEN
 *    wp_after_insert_post (unless told not to).
 *  - The REST controller calls wp_insert_post(..., false), so save_post runs
 *    BEFORE it saves the terms the block editor sent; it then saves them and
 *    fires wp_after_insert_post itself.
 *  - wp_trash_post() is wp_update_post() with post_status 'trash'.
 *  - wp_delete_post(..., true) fires before_delete_post while the post and its
 *    terms still exist, then removes them.
 *
 * ccm_tools_cf_api() is renamed out of the way in a temporary copy of the
 * module and replaced by a recorder, so every Cloudflare call is observed
 * rather than inferred from a return value. Nothing touches the network.
 *
 * Every bug case here was confirmed to FAIL against v8.13.9 before the fix.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

define('ABSPATH', $root . '/fake-wp/');
define('CCM_HELPER_VERSION', 'test');
define('AUTH_KEY', str_repeat('a', 64));
define('SECURE_AUTH_KEY', str_repeat('b', 64));
define('OBJECT', 'OBJECT');

// ── Hooks: a real registry, because the order things fire in IS the test ──

$GLOBALS['__hooks'] = array();

function add_action($hook, $cb, $priority = 10, $args = 1) {
    $GLOBALS['__hooks'][$hook][$priority][] = array($cb, (int) $args);
    ksort($GLOBALS['__hooks'][$hook]);
    return true;
}
function add_filter($hook, $cb, $priority = 10, $args = 1) {
    return add_action($hook, $cb, $priority, $args);
}
function remove_all_actions($hook) {
    unset($GLOBALS['__hooks'][$hook]);
}
function has_action($hook, $cb = false) {
    return !empty($GLOBALS['__hooks'][$hook]);
}
function do_action($hook, ...$args) {
    if (empty($GLOBALS['__hooks'][$hook])) {
        return;
    }
    // Iterate by priority, re-reading so a callback added mid-run still fires.
    $done = array();
    while (true) {
        $ran = false;
        foreach ($GLOBALS['__hooks'][$hook] ?? array() as $priority => $cbs) {
            foreach ($cbs as $i => $entry) {
                $key = $priority . ':' . $i;
                if (isset($done[$key])) {
                    continue;
                }
                $done[$key] = true;
                call_user_func_array($entry[0], array_slice($args, 0, $entry[1]));
                $ran = true;
                continue 3;
            }
        }
        if (!$ran) {
            break;
        }
    }
}
function apply_filters($hook, $value, ...$args) {
    foreach ($GLOBALS['__hooks'][$hook] ?? array() as $cbs) {
        foreach ($cbs as $entry) {
            $value = call_user_func_array($entry[0], array_slice(array_merge(array($value), $args), 0, max(1, $entry[1])));
        }
    }
    return $value;
}

// ── Options ──────────────────────────────────────────────────────────────

$GLOBALS['__options'] = array();
function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['__options']) ? $GLOBALS['__options'][$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['__options'][$key] = $value;
    return true;
}

// ── Small WordPress helpers ─────────────────────────────────────────────

class WP_Error {
    public $code;
    public $message;
    public function __construct($code = '', $message = '', $data = '') {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_message() { return $this->message; }
}
class WP_Post {
    public $ID = 0;
    public $post_type = 'post';
    public $post_status = 'draft';
    public $post_name = '';
    public $post_title = '';
    public $post_author = 1;
    public $post_parent = 0;
    public function __construct($fields = array()) {
        foreach ($fields as $k => $v) {
            $this->$k = $v;
        }
    }
}
class WP_Term {
    public $term_id;
    public $taxonomy;
    public $slug;
    public function __construct($id, $tax, $slug) {
        $this->term_id = $id;
        $this->taxonomy = $tax;
        $this->slug = $slug;
    }
}

function is_wp_error($t) { return $t instanceof WP_Error; }
function __($t, $d = null) { return $t; }
function _e($t, $d = null) { echo $t; }
function esc_html($t) { return (string) $t; }
function esc_attr($t) { return (string) $t; }
function esc_url($t) { return (string) $t; }
function esc_url_raw($t, $protocols = null) {
    $t = trim((string) $t);
    return preg_match('#^https?://#i', $t) ? $t : '';
}
function wp_unslash($t) { return $t; }
function sanitize_text_field($t) { return trim((string) $t); }
function wp_parse_url($u, $c = -1) { return parse_url((string) $u, $c); }
function wp_parse_args($args, $defaults = array()) {
    return array_merge((array) $defaults, is_array($args) ? $args : array());
}
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function get_bloginfo($k = '') { return '6.8'; }

// ── The simulated site ─────────────────────────────────────────────────

/** @var WP_Post[] */
$GLOBALS['__posts'] = array();
/** @var WP_Term[] */
$GLOBALS['__terms'] = array();
/** @var array<int, int[]> post id => term ids */
$GLOBALS['__rels'] = array();
$GLOBALS['__meta'] = array();
$GLOBALS['__next_id'] = 100;

function home_url($path = '') {
    $url = (string) get_option('home');
    if ($path && is_string($path)) {
        $url .= '/' . ltrim($path, '/');
    }
    return $url;
}

function get_post($p = null, $output = OBJECT) {
    if ($p instanceof WP_Post) {
        return $p;
    }
    $id = (int) $p;
    return isset($GLOBALS['__posts'][$id]) ? clone $GLOBALS['__posts'][$id] : null;
}

function get_permalink($post = 0) {
    $post = get_post($post);
    if (!$post) {
        return false;
    }
    if (in_array($post->post_status, array('draft', 'pending', 'auto-draft', 'future'), true) || $post->post_name === '') {
        return home_url('/?p=' . $post->ID);
    }
    return home_url('/' . $post->post_name . '/');
}

function is_post_type_viewable($type) {
    return in_array(is_object($type) ? $type->name : $type, array('post', 'page'), true);
}
function is_taxonomy_viewable($tax) {
    return in_array(is_object($tax) ? $tax->name : $tax, array('category', 'post_tag'), true);
}
function get_object_taxonomies($type, $output = 'names') {
    $names = $type === 'post' ? array('category', 'post_tag', 'post_format') : array();
    if ($output === 'names') {
        return $names;
    }
    $out = array();
    foreach ($names as $n) {
        $out[$n] = (object) array('name' => $n, 'public' => $n !== 'post_format');
    }
    return $out;
}
function wp_is_post_revision($p) {
    $post = get_post($p);
    return $post && $post->post_type === 'revision' ? (int) $post->post_parent : false;
}
function wp_is_post_autosave($p) {
    $post = get_post($p);
    return $post && $post->post_type === 'revision' && strpos($post->post_name, 'autosave') !== false
        ? (int) $post->post_parent : false;
}

function sim_term_base($tax) {
    return $tax === 'category' ? 'category' : ($tax === 'post_tag' ? 'tag' : null);
}
function get_term_link($term, $taxonomy = '') {
    if (!$term instanceof WP_Term) {
        $term = $GLOBALS['__terms'][(int) $term] ?? null;
    }
    if (!$term || ($taxonomy !== '' && $term->taxonomy !== $taxonomy) || !sim_term_base($term->taxonomy)) {
        return new WP_Error('invalid_term', 'Empty Term.');
    }
    return home_url('/' . sim_term_base($term->taxonomy) . '/' . $term->slug . '/');
}
function get_category_link($id) { $l = get_term_link((int) $id, 'category'); return is_wp_error($l) ? '' : $l; }
function get_tag_link($id) { $l = get_term_link((int) $id, 'post_tag'); return is_wp_error($l) ? '' : $l; }
function wp_get_object_terms($object_ids, $taxonomies, $args = array()) {
    $out = array();
    foreach ((array) $object_ids as $oid) {
        foreach ($GLOBALS['__rels'][(int) $oid] ?? array() as $tid) {
            $t = $GLOBALS['__terms'][$tid] ?? null;
            if ($t && in_array($t->taxonomy, (array) $taxonomies, true)) {
                $out[] = clone $t;
            }
        }
    }
    return $out;
}
function get_the_terms($post, $taxonomy) {
    $p = get_post($post);
    $terms = $p ? wp_get_object_terms($p->ID, $taxonomy) : array();
    return $terms ?: false;
}
function get_the_category($id = false) { return get_the_terms($id, 'category') ?: array(); }
function get_the_tags($id = 0) { return get_the_terms($id, 'post_tag'); }
function get_post_type_archive_link($type) {
    return $type === 'post' ? home_url('/') : false;
}
function get_author_posts_url($author_id, $nicename = '') { return home_url('/author/admin/'); }
function get_feed_link($feed = '') { return home_url('/feed/'); }
function get_bloginfo_rss($show = '') { return get_feed_link('rss2'); }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['__meta'][$id][$key] ?? ($single ? '' : array()); }

function sim_set_terms(int $post_id, array $term_ids, string $taxonomy) {
    $keep = array();
    foreach ($GLOBALS['__rels'][$post_id] ?? array() as $tid) {
        if ($GLOBALS['__terms'][$tid]->taxonomy !== $taxonomy) {
            $keep[] = $tid;
        }
    }
    $GLOBALS['__rels'][$post_id] = array_merge($keep, array_map('intval', $term_ids));
}
function sim_term_ids(int $post_id, string $taxonomy): array {
    return array_map(function ($t) { return $t->term_id; }, wp_get_object_terms($post_id, $taxonomy));
}

/** wp_insert_post(), in core's order. Returns the post id. */
function sim_insert_post(array $arr, bool $fire_after_hooks = true): int {
    $update = !empty($arr['ID']);
    $post_before = $update ? get_post($arr['ID']) : null;
    $previous_status = $update ? $post_before->post_status : 'new';
    $id = $update ? (int) $arr['ID'] : $GLOBALS['__next_id']++;

    $data = $update ? (array) $post_before : array(
        'ID' => $id, 'post_type' => 'post', 'post_status' => 'draft',
        'post_name' => '', 'post_title' => '', 'post_author' => 1, 'post_parent' => 0,
    );
    foreach (array('post_type', 'post_status', 'post_name', 'post_title', 'post_parent') as $k) {
        if (array_key_exists($k, $arr)) {
            $data[$k] = $arr[$k];
        }
    }

    // Categories are decided before the write; 'post' always gets at least one.
    if (!empty($arr['post_category'])) {
        $cats = $arr['post_category'];
    } elseif ($update && !isset($arr['post_category'])) {
        $cats = sim_term_ids($id, 'category');
    } else {
        $cats = array();
    }
    if (!$cats) {
        $cats = ($data['post_type'] === 'post' && $data['post_status'] !== 'auto-draft')
            ? array((int) get_option('default_category')) : array();
    }

    // Untrashing restores the slug the post had before it was binned.
    if ($previous_status === 'trash' && $data['post_status'] !== 'trash') {
        $desired = get_post_meta($id, '_wp_desired_post_slug', true);
        if ($desired) {
            unset($GLOBALS['__meta'][$id]['_wp_desired_post_slug']);
            $data['post_name'] = $desired;
        }
    }
    // Binning renames the slug, and core writes that straight to the table
    // BEFORE pre_post_update fires. $post_before already holds the old one.
    if ($data['post_status'] === 'trash' && $previous_status !== 'trash' && $previous_status !== 'new') {
        $GLOBALS['__meta'][$id]['_wp_desired_post_slug'] = $data['post_name'];
        $data['post_name'] .= '__trashed';
        $GLOBALS['__posts'][$id]->post_name = $data['post_name'];
    }

    if ($update) {
        do_action('pre_post_update', $id, $data);
    }

    $GLOBALS['__posts'][$id] = new WP_Post($data);

    if (in_array($data['post_type'], array('post'), true)) {
        sim_set_terms($id, $cats, 'category');
    }
    if (isset($arr['tags_input'])) {
        sim_set_terms($id, $arr['tags_input'], 'post_tag');
    }

    $post = get_post($id);
    do_action("save_post_{$post->post_type}", $id, $post, $update);
    do_action('save_post', $id, $post, $update);
    do_action('wp_insert_post', $id, $post, $update);

    if ($fire_after_hooks) {
        do_action('wp_after_insert_post', $id, get_post($id), $update, $post_before);
    }
    return $id;
}

/** WP_REST_Posts_Controller::update_item(): save, THEN terms, THEN after hooks. */
function sim_rest_update(array $arr, array $terms): int {
    $post_before = get_post($arr['ID']);
    $id = sim_insert_post($arr, false);
    foreach ($terms as $tax => $ids) {
        sim_set_terms($id, $ids, $tax);
    }
    do_action('wp_after_insert_post', $id, get_post($id), true, $post_before);
    return $id;
}

function sim_trash(int $id) {
    do_action('wp_trash_post', $id, get_post($id)->post_status);
    sim_insert_post(array('ID' => $id, 'post_status' => 'trash'));
    do_action('trashed_post', $id, 'publish');
}
function sim_untrash(int $id, string $to = 'draft') {
    sim_insert_post(array('ID' => $id, 'post_status' => $to));
}
function sim_delete(int $id) {
    $post = get_post($id);
    do_action('before_delete_post', $id, $post);
    unset($GLOBALS['__rels'][$id]);
    do_action('delete_post', $id, $post);
    unset($GLOBALS['__posts'][$id]);
    do_action('deleted_post', $id, $post);
    do_action('after_delete_post', $id, $post);
}

// ── Load the real module with the API swapped for a recorder ─────────────

$GLOBALS['__cf_calls'] = array();

function ccm_tools_cf_api(string $endpoint, string $method = 'GET', array $body = array(), string $token = '', bool $graphql = false) {
    $GLOBALS['__cf_calls'][] = array('endpoint' => $endpoint, 'method' => $method, 'body' => $body);
    return array('success' => true, 'result' => array('id' => 'x'));
}

$src = (string) file_get_contents($root . '/inc/cloudflare.php');
$swapped = str_replace('function ccm_tools_cf_api(', 'function ccm_tools_cf_api__real(', $src, $n);
if ($n !== 1) {
    fwrite(STDERR, "expected exactly one ccm_tools_cf_api() declaration, found $n\n");
    exit(2);
}
$tmp = sys_get_temp_dir() . '/ccm-cf-auto-purge-' . getmypid() . '.php';
file_put_contents($tmp, $swapped);
require $tmp;
@unlink($tmp);

$GLOBALS['__boot_hooks'] = $GLOBALS['__hooks'];

// ── Test plumbing ─────────────────────────────────────────────────────────

const ZONE = '0123456789abcdef0123456789abcdef';

/** A fresh request on a fresh site, with the given settings and home URL. */
function sim_boot(array $cf = array(), string $home = 'https://example.com', ?callable $before_init = null) {
    // Whatever the last case left running finishes first, as a real request would.
    sim_end_request();
    $GLOBALS['__hooks'] = $GLOBALS['__boot_hooks'];
    if ($before_init) {
        $before_init();
    }
    $GLOBALS['__options'] = array(
        'home' => $home,
        'default_category' => 1,
        'ccm_tools_cf_settings' => array_merge(
            array('zone_id' => ZONE, 'connected' => true, 'auto_purge' => true),
            $cf
        ),
    );
    $GLOBALS['__posts'] = array();
    $GLOBALS['__rels'] = array();
    $GLOBALS['__meta'] = array();
    $GLOBALS['__terms'] = array(
        1 => new WP_Term(1, 'category', 'uncategorized'),
        2 => new WP_Term(2, 'category', 'news'),
        3 => new WP_Term(3, 'category', 'sport'),
        4 => new WP_Term(4, 'post_tag', 'footy'),
    );
    $GLOBALS['__cf_calls'] = array();
    do_action('init');
}

/** End this request: shutdown runs, then the next request starts clean. */
function sim_end_request() {
    do_action('shutdown');
    remove_all_actions('shutdown');
}

/** Start counting Cloudflare calls from here. */
function sim_watch() {
    sim_end_request();
    $GLOBALS['__cf_calls'] = array();
}

/** A live post in News with the tag footy, created the way the editor does. */
function sim_live_post(string $slug = 'hello-world'): int {
    $id = sim_insert_post(array('post_status' => 'auto-draft'));
    sim_rest_update(
        array('ID' => $id, 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => 'Hello'),
        array('category' => array(2), 'post_tag' => array(4))
    );
    return $id;
}

function purged_urls(): array {
    $urls = array();
    foreach ($GLOBALS['__cf_calls'] as $c) {
        foreach ($c['body']['files'] ?? array() as $u) {
            $urls[] = $u;
        }
    }
    return $urls;
}
function purged_all(): bool {
    foreach ($GLOBALS['__cf_calls'] as $c) {
        if (!empty($c['body']['purge_everything'])) {
            return true;
        }
    }
    return false;
}

$GLOBALS['__fail'] = 0;
$GLOBALS['__pass'] = 0;
function check(string $label, bool $ok, string $detail = '') {
    if ($ok) {
        $GLOBALS['__pass']++;
        echo "  ok    $label\n";
    } else {
        $GLOBALS['__fail']++;
        echo "  FAIL  $label\n";
        if ($detail !== '') {
            echo '        ' . str_replace("\n", "\n        ", $detail) . "\n";
        }
    }
}
function has_url(string $url): bool { return in_array($url, purged_urls(), true); }
function seen(): string {
    $calls = count($GLOBALS['__cf_calls']);
    return "calls: $calls" . (purged_all() ? ' (purge_everything)' : '')
        . "\npurged: " . (purged_urls() ? implode(', ', purged_urls()) : '(none)');
}

echo "Cloudflare auto-purge\n";

// ── The fixture itself ────────────────────────────────────────────────────

echo "\nfixture\n";
sim_boot();
$id = sim_insert_post(array('post_status' => 'auto-draft'));
$saw = array();
add_action('save_post', function ($pid) use (&$saw) { $saw[] = sim_term_ids($pid, 'category'); }, 5, 1);
sim_rest_update(array('ID' => $id, 'post_status' => 'publish', 'post_name' => 'x'), array('category' => array(2)));
check('the REST save fires save_post before the editor\'s categories are saved',
    $saw === array(array(1)), 'save_post saw category ids ' . json_encode($saw));
sim_trash($id);
check('binning renames the slug to __trashed, as core does', get_post($id)->post_name === 'x__trashed');

// ── 1. First publish from the block editor (bug 4) ────────────────────────

echo "\nfirst publish, categories saved after save_post (bug 4)\n";
sim_boot();
$id = sim_insert_post(array('post_status' => 'auto-draft'));
sim_watch();
sim_rest_update(
    array('ID' => $id, 'post_status' => 'publish', 'post_name' => 'hello-world', 'post_title' => 'Hello'),
    array('category' => array(2), 'post_tag' => array(4))
);
sim_end_request();
check('purges the new post', has_url('https://example.com/hello-world/'), seen());
check('purges the category the editor assigned', has_url('https://example.com/category/news/'), seen());
check('purges the tag the editor assigned', has_url('https://example.com/tag/footy/'), seen());
check('purges the home page and feed', has_url('https://example.com/') && has_url('https://example.com/feed/'), seen());

// ── 2. Update a live post ─────────────────────────────────────────────────

echo "\nupdate a live post\n";
sim_boot();
$id = sim_live_post();
sim_watch();
sim_insert_post(array('ID' => $id, 'post_title' => 'Hello again'));
sim_end_request();
check('purges the post', has_url('https://example.com/hello-world/'), seen());
check('purges its category', has_url('https://example.com/category/news/'), seen());
check('purges the author archive', has_url('https://example.com/author/admin/'), seen());

// ── 3. Unpublish (bug 1) ──────────────────────────────────────────────────

foreach (array('draft', 'private', 'pending') as $to) {
    echo "\nunpublish: publish to $to (bug 1)\n";
    sim_boot();
    $id = sim_live_post();
    sim_watch();
    sim_insert_post(array('ID' => $id, 'post_status' => $to));
    sim_end_request();
    check("purges the URL that was live", has_url('https://example.com/hello-world/'), seen());
    check("purges the category page it was listed on", has_url('https://example.com/category/news/'), seen());
    check("does not purge a ?p= preview URL", !has_url('https://example.com/?p=' . $id), seen());
}

// ── 4. Bin, then restore (bug 2) ──────────────────────────────────────────

echo "\nbin a live post (bug 2)\n";
sim_boot();
$id = sim_live_post();
sim_watch();
sim_trash($id);
sim_end_request();
check('purges the URL that was live', has_url('https://example.com/hello-world/'), seen());
check('never purges a __trashed URL', strpos(implode(' ', purged_urls()), '__trashed') === false, seen());
check('purges the category page it was listed on', has_url('https://example.com/category/news/'), seen());

echo "\nrestore from the bin\n";
sim_watch();
sim_untrash($id, 'draft');
sim_end_request();
check('restore to draft (core default) purges nothing', count($GLOBALS['__cf_calls']) === 0, seen());
sim_watch();
sim_insert_post(array('ID' => $id, 'post_status' => 'publish'));
sim_end_request();
check('publishing it again purges its original URL', has_url('https://example.com/hello-world/'), seen());
check('and no __trashed URL', strpos(implode(' ', purged_urls()), '__trashed') === false, seen());

echo "\nrestore straight to publish\n";
sim_boot();
$id = sim_live_post();
sim_trash($id);
sim_watch();
sim_untrash($id, 'publish');
sim_end_request();
check('purges the restored URL', has_url('https://example.com/hello-world/'), seen());
check('and no __trashed URL', strpos(implode(' ', purged_urls()), '__trashed') === false, seen());

// ── 5. Permanent delete of a live post ────────────────────────────────────

echo "\npermanent delete of a live post\n";
sim_boot();
$id = sim_live_post();
sim_watch();
sim_delete($id);
sim_end_request();
check('purges the URL that was live', has_url('https://example.com/hello-world/'), seen());
check('purges the category page it was listed on', has_url('https://example.com/category/news/'), seen());

echo "\npermanent delete of a binned post\n";
sim_boot();
$id = sim_live_post();
sim_trash($id);
sim_watch();
sim_delete($id);
sim_end_request();
check('purges nothing (it was purged when binned)', count($GLOBALS['__cf_calls']) === 0, seen());

// ── 6. Slug rename (bug 3) ────────────────────────────────────────────────

echo "\nslug rename (bug 3)\n";
sim_boot();
$id = sim_live_post();
sim_watch();
sim_insert_post(array('ID' => $id, 'post_name' => 'hello-world-renamed'));
sim_end_request();
check('purges the old URL', has_url('https://example.com/hello-world/'), seen());
check('purges the new URL', has_url('https://example.com/hello-world-renamed/'), seen());

// ── 7. Category change (bug 4) ────────────────────────────────────────────

echo "\ncategory change, classic editor (bug 4)\n";
sim_boot();
$id = sim_live_post();
sim_watch();
sim_insert_post(array('ID' => $id, 'post_category' => array(3)));
sim_end_request();
check('purges the category it left', has_url('https://example.com/category/news/'), seen());
check('purges the category it joined', has_url('https://example.com/category/sport/'), seen());

echo "\ncategory change, block editor (bug 4)\n";
sim_boot();
$id = sim_live_post();
sim_watch();
sim_rest_update(array('ID' => $id), array('category' => array(3)));
sim_end_request();
check('purges the category it left', has_url('https://example.com/category/news/'), seen());
check('purges the category it joined', has_url('https://example.com/category/sport/'), seen());

// ── 8. Things that must not purge ─────────────────────────────────────────

echo "\nautosave, revision, never-live draft\n";
sim_boot();
$id = sim_live_post();
sim_watch();
sim_insert_post(array('post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_name' => $id . '-autosave-v1'));
sim_end_request();
check('an autosave purges nothing', count($GLOBALS['__cf_calls']) === 0, seen());
sim_watch();
sim_insert_post(array('post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_name' => $id . '-revision-v1'));
sim_end_request();
check('a revision purges nothing', count($GLOBALS['__cf_calls']) === 0, seen());
$draft = sim_insert_post(array('post_status' => 'auto-draft'));
sim_watch();
sim_rest_update(array('ID' => $draft, 'post_status' => 'draft', 'post_name' => 'wip'), array('category' => array(2)));
sim_insert_post(array('ID' => $draft, 'post_title' => 'still wip'));
sim_end_request();
check('saving a draft that was never live purges nothing', count($GLOBALS['__cf_calls']) === 0, seen());

// ── 9. Widgets (bug 5) ────────────────────────────────────────────────────

echo "\nwidget save (bug 5)\n";
sim_boot();
sim_watch();
do_action('update_option_sidebars_widgets', array(), array('sidebar-1' => array('text-2')), 'sidebars_widgets');
sim_end_request();
check('does not purge the whole zone by default', !purged_all(), seen());

echo "\nwidget save, opted in\n";
sim_boot(array('purge_widgets' => true));
sim_watch();
do_action('update_option_sidebars_widgets', array(), array('sidebar-1' => array('text-2')), 'sidebars_widgets');
sim_end_request();
check('purges everything when the setting is on', purged_all(), seen());

// ── 10. Headless mapping (bug 6) ──────────────────────────────────────────

echo "\nheadless: home /tsb, public URL at the root (bug 6)\n";
sim_boot(array('public_url' => 'https://example.com'), 'https://example.com/tsb');
$id = sim_live_post();
sim_watch();
sim_insert_post(array('ID' => $id, 'post_title' => 'Edited'));
sim_end_request();
check('purges the public post URL', has_url('https://example.com/hello-world/'), seen());
check('purges the public category URL', has_url('https://example.com/category/news/'), seen());
check('purges the public home page', has_url('https://example.com/'), seen());
check('purges nothing under /tsb', strpos(implode(' ', purged_urls()), '/tsb') === false, seen());

echo "\nheadless: trailing slash on the setting\n";
sim_boot(array('public_url' => 'https://example.com/'), 'https://example.com/tsb');
$id = sim_live_post();
sim_watch();
sim_insert_post(array('ID' => $id, 'post_title' => 'Edited'));
sim_end_request();
check('still maps to the public post URL', has_url('https://example.com/hello-world/'), seen());
check('no doubled slash', strpos(implode(' ', purged_urls()), 'example.com//') === false, seen());

echo "\nheadless: a page outside the WordPress prefix is left alone\n";
sim_boot(array('public_url' => 'https://example.com'), 'https://example.com/tsb');
add_filter('ccm_tools_cf_purge_url_list', function ($urls) {
    $urls[] = 'https://cdn.example.net/hello-world/';
    return $urls;
});
$id = sim_live_post();
sim_watch();
sim_insert_post(array('ID' => $id, 'post_title' => 'Edited'));
sim_end_request();
check('the ccm_tools_cf_purge_url_list filter can add a URL', has_url('https://cdn.example.net/hello-world/'), seen());

echo "\nheadless: the filter can replace the list\n";
sim_boot(array('public_url' => 'https://example.com'), 'https://example.com/tsb');
$GLOBALS['__filter_ids'] = null;
add_filter('ccm_tools_cf_purge_url_list', function ($urls, $post_ids) {
    $GLOBALS['__filter_ids'] = $post_ids;
    return array('https://example.com/only-this/');
}, 10, 2);
$id = sim_live_post();
sim_watch();
sim_insert_post(array('ID' => $id, 'post_title' => 'Edited'));
sim_end_request();
check('only what the filter returned is purged', purged_urls() === array('https://example.com/only-this/'), seen());
check('the filter is told which posts changed', $GLOBALS['__filter_ids'] === array($id),
    'got ' . json_encode($GLOBALS['__filter_ids']));

// ── 11. Empty public URL keeps today's behaviour ──────────────────────────

echo "\nempty public URL keeps the WordPress URLs\n";
sim_boot(array('public_url' => ''), 'https://example.com/tsb');
$id = sim_live_post();
sim_watch();
sim_insert_post(array('ID' => $id, 'post_title' => 'Edited'));
sim_end_request();
check('purges the WordPress post URL', has_url('https://example.com/tsb/hello-world/'), seen());
check('purges the WordPress category URL', has_url('https://example.com/tsb/category/news/'), seen());

// ── 12. Once per request (bug 7) ──────────────────────────────────────────

echo "\ntwo saves in one request (bug 7)\n";
sim_boot();
$id = sim_live_post();
sim_watch();
sim_insert_post(array('ID' => $id, 'post_title' => 'One'));
sim_insert_post(array('ID' => $id, 'post_title' => 'Two'));
$before_shutdown = count($GLOBALS['__cf_calls']);
sim_end_request();
check('nothing is sent while the save request is still running', $before_shutdown === 0,
    "$before_shutdown call(s) before shutdown");
check('exactly one Cloudflare call', count($GLOBALS['__cf_calls']) === 1, seen());
check('each URL sent once', count(purged_urls()) === count(array_unique(purged_urls())), seen());

echo "\na full purge and a URL purge in one request\n";
sim_boot();
$id = sim_live_post();
sim_watch();
sim_insert_post(array('ID' => $id, 'post_title' => 'One'));
do_action('wp_update_nav_menu', 5);
sim_end_request();
check('collapses to a single purge_everything', count($GLOBALS['__cf_calls']) === 1 && purged_all(), seen());

// ── Kill switch ───────────────────────────────────────────────────────────

echo "\nkill switch filter\n";
sim_boot(array(), 'https://example.com', function () {
    add_filter('ccm_tools_cf_auto_purge_enabled', function () { return false; });
});
$id = sim_live_post();
sim_insert_post(array('ID' => $id, 'post_status' => 'draft'));
sim_end_request();
check('ccm_tools_cf_auto_purge_enabled = false stops every auto-purge', count($GLOBALS['__cf_calls']) === 0, seen());

echo "\nkill switch filter can also turn it on from code\n";
sim_boot(array('auto_purge' => false), 'https://example.com', function () {
    add_filter('ccm_tools_cf_auto_purge_enabled', function () { return true; });
});
$id = sim_live_post();
sim_end_request();
check('= true enables it even when the stored setting is off', has_url('https://example.com/hello-world/'), seen());

// ── The public functions themes call directly ─────────────────────────────

echo "\nccm_tools_cf_purge_urls() / ccm_tools_cf_purge_all() contract\n";
sim_boot(array('public_url' => 'https://example.com'), 'https://example.com/tsb');
sim_watch();
$many = array();
for ($i = 0; $i < 31; $i++) {
    $many[] = 'https://example.com/tsb/p' . $i . '/';
}
$ret = ccm_tools_cf_purge_urls($many);
check('purge_urls returns true', $ret === true, var_export($ret, true));
check('purge_urls chunks at 30 (31 URLs = 2 calls)', count($GLOBALS['__cf_calls']) === 2, seen());
check('purge_urls sends what it was given, unmapped', purged_urls() === $many, seen());
$GLOBALS['__cf_calls'] = array();
$ret = ccm_tools_cf_purge_all();
check('purge_all returns true and sends one purge_everything', $ret === true && purged_all() && count($GLOBALS['__cf_calls']) === 1, seen());
$GLOBALS['__options']['ccm_tools_cf_settings']['zone_id'] = '';
check('purge_urls without a zone returns WP_Error', ccm_tools_cf_purge_urls(array('https://example.com/')) instanceof WP_Error);

// ── Result ──────────────────────────────────────────────────────────────

echo "\n";
if ($GLOBALS['__fail']) {
    printf("%d of %d checks FAILED\n", $GLOBALS['__fail'], $GLOBALS['__fail'] + $GLOBALS['__pass']);
    exit(1);
}
printf("all %d checks passed\n", $GLOBALS['__pass']);
