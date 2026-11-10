<?php
/**
 * The WebP counter and the bulk queue must agree, and the queue must pick up
 * what the counter says is waiting.
 *
 * evse.com.au, 2026-11-10: the page said 362 images were waiting and Convert
 * only ever ran 2. The counter counted image FILES with no .webp on disk; the
 * queue only took attachments with no _ccm_webp_converted marker. Thumbnails
 * missing their .webp on marked attachments were counted and never queued.
 * The 2 it did run were 33 MB and 45 MB PNGs that timed out, and the summary
 * blamed CMYK or folder permissions.
 *
 * This builds that shape on disk and drives the real counter, the real queue
 * handler and the real convert handler, with only the image encoder faked.
 *
 * Run:  php tests/webp_queue_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
define('ABSPATH', __DIR__ . '/');

$tmp = sys_get_temp_dir() . '/ccm-webp-queue-' . getmypid();
@mkdir($tmp, 0777, true);
register_shutdown_function(function () use ($tmp) {
    foreach (glob($tmp . '/*') ?: array() as $f) { @unlink($f); }
    @rmdir($tmp);
});

// ── Minimal WordPress ──

$GLOBALS['__meta'] = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__attachments'] = array();
$GLOBALS['__post'] = array();

function add_action(...$a) { return true; }
function add_filter(...$a) { return true; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function get_option($k, $d = false) { return $d; }
function wp_parse_args($a, $d) { return array_merge($d, is_array($a) ? $a : array()); }
function wp_unslash($v) { return $v; }
function check_ajax_referer(...$a) { return true; }
function ccm_tools_user_is_admin() { return true; }
function size_format($b) { return round($b / 1048576, 1) . ' MB'; }
function get_the_title($id) { return 'Image ' . $id; }
function wp_get_attachment_url($id) { return 'https://example.test/' . $id; }
function get_post_mime_type($id) { return 'image/png'; }
function get_attached_file($id) { return $GLOBALS['__attachments'][$id]['file'] ?? false; }
function wp_get_attachment_metadata($id) { return $GLOBALS['__attachments'][$id]['meta'] ?? array(); }
function get_post_meta($id, $k = '', $single = false) { return $GLOBALS['__meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['__meta'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['__meta'][$id][$k]); return true; }
function get_transient($k) { return $GLOBALS['__transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }

class CCM_Test_Json_Sent extends Exception {
    public $payload; public $ok;
    public function __construct($payload, bool $ok) { parent::__construct('json'); $this->payload = $payload; $this->ok = $ok; }
}
function wp_send_json_success($d = null) { throw new CCM_Test_Json_Sent($d, true); }
function wp_send_json_error($d = null) { throw new CCM_Test_Json_Sent($d, false); }

class wpdb {
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public function prepare($q, ...$args) {
        $args = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;
        foreach ($args as $a) { $q = preg_replace('/%d/', (string) (int) $a, $q, 1); }
        return $q;
    }
    public function get_col($q) {
        $ids = array_keys($GLOBALS['__attachments']);
        rsort($ids);
        if (stripos($q, 'pm.meta_id IS NULL') !== false) {
            preg_match('/p\.ID < (\d+)/', $q, $m);
            return array_values(array_filter($ids, function ($id) use ($m) {
                return $id < (int) $m[1] && empty($GLOBALS['__meta'][$id]['_ccm_webp_converted']);
            }));
        }
        preg_match('/LIMIT (\d+)/', $q, $m);
        return array_slice($ids, 0, (int) $m[1]);
    }
    // The query shapes before 8.14.1, so this test can be shown to fail on that code.
    private function unmarked() {
        $ids = array_keys($GLOBALS['__attachments']);
        sort($ids);
        return array_values(array_filter($ids, function ($id) { return empty($GLOBALS['__meta'][$id]['_ccm_webp_converted']); }));
    }
    public function get_results($q) {
        $ids = stripos($q, 'pm.meta_id IS NULL') !== false ? $this->unmarked() : $this->get_col($q);
        return array_map(function ($id) { return (object) array('ID' => $id, 'post_title' => 'Image ' . $id, 'guid' => ''); }, $ids);
    }
    public function get_var($q) { return count($this->unmarked()); }
}
$GLOBALS['wpdb'] = new wpdb();

// ── Real module code, with only the encoder swapped for a fake ──

$src = file_get_contents($root . '/inc/webp-converter.php');
$src = str_replace('function ccm_tools_webp_convert_image(', 'function ccm_tools_webp_convert_image__real(', $src, $n);
if ($n !== 1) { fwrite(STDERR, "could not find the encoder to fake\n"); exit(2); }
eval('?>' . $src);
require $root . '/inc/ajax-handlers.php';

$GLOBALS['__encoded'] = array();
function ccm_tools_webp_convert_image($source, $dest = '', $q = 82, $ext = 'auto') {
    $GLOBALS['__encoded'][] = basename($source);
    if (basename($source) === 'huge.png') {
        return array('success' => false, 'message' => 'ImageMagick failed to write the WebP file.');
    }
    $webp = ccm_tools_webp_sibling_path($source);
    file_put_contents($webp, 'RIFF');
    return array('success' => true, 'source_size' => 100, 'dest_size' => 40, 'extension_used' => 'imagick', 'dest_path' => $webp);
}

// ── The evse shape ──

function make_attachment(string $tmp, int $id, string $name, array $sizes, array $have_webp, bool $marked): void {
    $full = "$tmp/$name.png";
    file_put_contents($full, str_repeat('x', 10));
    $meta = array('sizes' => array());
    foreach ($sizes as $s) {
        $file = "$name-$s.png";
        file_put_contents("$tmp/$file", 'x');
        $meta['sizes'][$s] = array('file' => $file);
    }
    foreach ($have_webp as $f) {
        file_put_contents("$tmp/" . preg_replace('/\.png$/', '.webp', $f), 'RIFF');
    }
    $GLOBALS['__attachments'][$id] = array('file' => $full, 'meta' => $meta);
    if ($marked) {
        $GLOBALS['__meta'][$id]['_ccm_webp_converted'] = array('full' => array('success' => true));
    }
}

// 10: fully converted. 11, 12: marked, but thumbnails missing their .webp (the 360).
// 13: never converted. 14: never converted, original fails (the 45 MB PNG).
make_attachment($tmp, 10, 'done', array('thumb', 'medium'), array('done.png', 'done-thumb.png', 'done-medium.png'), true);
make_attachment($tmp, 11, 'partA', array('thumb', 'medium', 'large'), array('partA.png', 'partA-thumb.png'), true);
make_attachment($tmp, 12, 'partB', array('thumb', 'new-size'), array('partB.png', 'partB-thumb.png'), true);
make_attachment($tmp, 13, 'fresh', array('thumb'), array(), false);
make_attachment($tmp, 14, 'huge', array('thumb'), array(), false);

$fail = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $what . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) { $fail++; }
}
function call(string $handler, array $post) {
    $_POST = $post;
    try { $handler(); } catch (CCM_Test_Json_Sent $s) { return array($s->ok, $s->payload); }
    return array(null, null);
}

echo "WebP counter / queue agreement\n";

$stats = ccm_tools_webp_get_statistics();
// Missing: partA medium+large (2), partB new-size (1), fresh full+thumb (2), huge full+thumb (2) = 7.
check('counter counts the files with no .webp', $stats['pending_conversion'] === 7, (string) $stats['pending_conversion']);
check('header arithmetic agrees with the counter', $stats['total_images'] - $stats['converted_images'] === 7,
    $stats['total_images'] . ' - ' . $stats['converted_images']);

[$ok, $q] = call('ccm_tools_ajax_get_unconverted_images', array('offset' => 0, 'limit' => 50));
$queued = array_column($q['images'], 'id');
check('queue total_files equals the counter', $q['total_files'] === $stats['pending_conversion'], (string) $q['total_files']);
check('marked attachments with missing thumbnails are queued', in_array(11, $queued, true) && in_array(12, $queued, true), implode(',', $queued));
check('never-converted attachments are queued', in_array(13, $queued, true) && in_array(14, $queued, true));
check('fully converted attachment is not queued', !in_array(10, $queued, true));

echo "Convert handler\n";

$GLOBALS['__encoded'] = array();
[$ok, $r] = call('ccm_tools_ajax_convert_single_image', array('attachment_id' => 11));
check('marked attachment: only the missing files are encoded', $ok && $GLOBALS['__encoded'] === array('partA-medium.png', 'partA-large.png'),
    implode(',', $GLOBALS['__encoded']));
check('nothing remaining after it', $ok && $r['remaining'] === 0);
check('earlier conversions kept in the marker', isset($GLOBALS['__meta'][11]['_ccm_webp_converted']['full'], $GLOBALS['__meta'][11]['_ccm_webp_converted']['large']));

$GLOBALS['__encoded'] = array();
[$ok, $r] = call('ccm_tools_ajax_convert_single_image', array('attachment_id' => 14));
check('huge original: thumbnail converted before the original is tried', $GLOBALS['__encoded'] === array('huge-thumb.png', 'huge.png'),
    implode(',', $GLOBALS['__encoded']));
check('huge original: thumbnail recorded although the original failed', isset($GLOBALS['__meta'][14]['_ccm_webp_converted']['thumb']));
check('huge original: the real reason is reported, per file', $ok && count($r['failed']) === 1 && $r['failed'][0]['size'] === 'full'
    && strpos($r['message'], 'huge.png') !== false && stripos($r['message'], 'CMYK') === false, $r['message'] ?? '');

$GLOBALS['__encoded'] = array();
[$ok, $r] = call('ccm_tools_ajax_convert_single_image', array('attachment_id' => 14, 'skip' => array('full')));
check('a size that failed this run is skipped when asked', $GLOBALS['__encoded'] === array(), implode(',', $GLOBALS['__encoded']));

[$ok, $r] = call('ccm_tools_ajax_convert_single_image', array('attachment_id' => 14));
check('a file that fails with nothing else to do is an error with its reason', $ok === false && strpos($r['message'], 'huge.png') !== false, $r['message'] ?? '');

call('ccm_tools_ajax_convert_single_image', array('attachment_id' => 12));
call('ccm_tools_ajax_convert_single_image', array('attachment_id' => 13));

$stats = ccm_tools_webp_get_statistics();
[$ok, $q] = call('ccm_tools_ajax_get_unconverted_images', array('offset' => 0, 'limit' => 50));
check('afterwards only the failing original is waiting, in both places',
    $stats['pending_conversion'] === 1 && $q['total_files'] === 1 && array_column($q['images'], 'id') === array(14),
    $stats['pending_conversion'] . ' / ' . $q['total_files']);

echo $fail ? "\n$fail check(s) failed\n" : "\nAll checks passed\n";
exit($fail ? 1 : 0);
