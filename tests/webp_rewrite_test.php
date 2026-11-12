<?php
/**
 * The WebP output-buffer rewrite must reach every image URL a theme can put in
 * the page, not just the easy ones.
 *
 * chantalleaesthetics.com.au, 2026-11-12: WebP on, every .webp sibling on disk,
 * yet the header and footer backgrounds (924 KB and 4.28 MB PNG, every page)
 * and the home hero's video poster (3.09 MB JPG) were still served as the
 * originals. Three holes:
 *
 *  1. style="...url('x.png')..." was never matched. The attribute regex read
 *     the value as [^"']*, so the single quote inside url(...) ended it and the
 *     closing " never lined up. Any theme that writes a quoted url() inside a
 *     double-quoted attribute (most hand-written PHP themes) was skipped.
 *  2. url(&quot;x.png&quot;) and url(&#039;x.png&#039;), which is what esc_attr()
 *     makes of quoted url()s, never matched the url() pattern.
 *  3. <video poster="..."> was never rewritten, and a page whose only image was
 *     a poster bailed out early because it had no <img or <source.
 *
 * This puts real .png/.jpg files and .webp siblings in a fake uploads folder
 * and drives the real rewrite functions. Only the encoder and the background
 * queue are faked.
 *
 * Run:  php tests/webp_rewrite_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);

$tmp = sys_get_temp_dir() . '/ccm-webp-rewrite-' . getmypid();
$up  = $tmp . '/uploads';
@mkdir($up . '/2026/05', 0777, true);
register_shutdown_function(function () use ($tmp) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

// Originals with a .webp sibling, and one without.
foreach (array('bg.png', 'marble.png', 'hero.jpg') as $f) {
    file_put_contents("$up/2026/05/$f", 'x');
    file_put_contents("$up/2026/05/" . preg_replace('/\.(png|jpg)$/', '.webp', $f), 'RIFF');
}
file_put_contents("$up/2026/05/nosibling.png", 'x');

// ── Minimal WordPress ──

$GLOBALS['__transients'] = array();
$GLOBALS['__converts'] = 0;
$GLOBALS['__queued'] = array();

function add_action(...$a) { return true; }
function add_filter(...$a) { return true; }
function apply_filters($tag, $v, ...$a) { return $v; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function get_option($k, $d = false) { return $d; }
function wp_parse_args($a, $d) { return array_merge($d, is_array($a) ? $a : array()); }
function get_transient($k) { return $GLOBALS['__transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }
function wp_upload_dir() {
    global $up;
    return array('basedir' => $up, 'baseurl' => 'https://site.test/wp-content/uploads');
}

$src = file_get_contents($root . '/inc/webp-converter.php');
$src = str_replace('function ccm_tools_webp_convert_image(', 'function ccm_tools_webp_convert_image__real(', $src, $n1);
$src = str_replace('function ccm_tools_webp_queue_for_conversion(', 'function ccm_tools_webp_queue_for_conversion__real(', $src, $n2);
if ($n1 !== 1 || $n2 !== 1) { fwrite(STDERR, "could not swap encoder/queue ($n1, $n2)\n"); exit(2); }
eval('?>' . $src);

// A missing sibling must stay missing: conversion "fails", the queue records it.
function ccm_tools_webp_convert_image($s, $d = '', $q = 82, $e = 'auto') { $GLOBALS['__converts']++; return array('success' => false); }
function ccm_tools_webp_queue_for_conversion($url) { $GLOBALS['__queued'][] = $url; return true; }

// ── Checks ──

$U = 'https://site.test/wp-content/uploads/2026/05';
$fail = 0; $pass = 0;
function check($name, $got, $want) {
    global $fail, $pass;
    if ($got === $want) { $pass++; return; }
    $fail++;
    echo "FAIL  $name\n  want: $want\n  got:  $got\n";
}
$bg  = 'ccm_tools_webp_convert_bg_images_in_html';
$img = function ($h) { return ccm_tools_webp_process_img_tags($h, wp_upload_dir()); };

// 1. The chantalleaesthetics markup, verbatim shape.
check('custom property, single-quoted url() in double-quoted style',
    $bg("<header id=\"site-header\" style=\"--header-bg-image: url('$U/bg.png')\">"),
    "<header id=\"site-header\" style=\"--header-bg-image: url('$U/bg.webp')\">");
check('footer custom property',
    $bg("<div class=\"footer-bar\" style=\"--footer-bar-bg: url('$U/marble.png')\">"),
    "<div class=\"footer-bar\" style=\"--footer-bar-bg: url('$U/marble.webp')\">");

// 2. The mirror image: double-quoted url() in a single-quoted attribute.
check('double-quoted url() in single-quoted style',
    $bg("<div style='background-image: url(\"$U/bg.png\")'>"),
    "<div style='background-image: url(\"$U/bg.webp\")'>");

// 3. Shapes that worked before must keep working.
check('unquoted url()',
    $bg("<div style=\"background-image: url($U/bg.png)\">"),
    "<div style=\"background-image: url($U/bg.webp)\">");
check('style block',
    $bg("<style>.a{background:url('$U/bg.png')}</style>"),
    "<style>.a{background:url('$U/bg.webp')}</style>");

// 4. What esc_attr() makes of a quoted url().
check('&quot; quoted url()',
    $bg("<div style=\"background-image: url(&quot;$U/bg.png&quot;)\">"),
    "<div style=\"background-image: url(&quot;$U/bg.webp&quot;)\">");
check('&#039; quoted url()',
    $bg("<div style=\"background-image: url(&#039;$U/bg.png&#039;)\">"),
    "<div style=\"background-image: url(&#039;$U/bg.webp&#039;)\">");

// 5. Several url()s in one attribute, mixed quoting.
check('two url()s in one style',
    $bg("<div style=\"background: url('$U/bg.png'), url($U/marble.png)\">"),
    "<div style=\"background: url('$U/bg.webp'), url($U/marble.webp)\">");

// 6. Stay inside the style attribute.
check('neighbouring attribute untouched',
    $bg("<a style=\"background:url('$U/bg.png')\" title=\"it's\" data-x='url($U/marble.png)'>"),
    "<a style=\"background:url('$U/bg.webp')\" title=\"it's\" data-x='url($U/marble.png)'>");
check('data attribute alone untouched',
    $bg("<div data-bg='url($U/bg.png)' style=\"color:red\">"),
    "<div data-bg='url($U/bg.png)' style=\"color:red\">");

// 7. Leave alone what we cannot serve.
check('outside uploads untouched',
    $bg("<div style=\"background:url('https://site.test/wp-content/plugins/p/a.png')\">"),
    "<div style=\"background:url('https://site.test/wp-content/plugins/p/a.png')\">");
check('no sibling untouched',
    $bg("<div style=\"background:url('$U/nosibling.png')\">"),
    "<div style=\"background:url('$U/nosibling.png')\">");
check('mismatched quotes untouched',
    $bg("<div style=\"background:url('$U/bg.png&quot;)\">"),
    "<div style=\"background:url('$U/bg.png&quot;)\">");

// 8. Video posters.
check('poster, double quotes',
    $img("<video autoplay muted poster=\"$U/hero.jpg\"><source src=\"$U/hero.mp4\" type=\"video/mp4\"></video>"),
    "<video autoplay muted poster=\"$U/hero.webp\"><source src=\"$U/hero.mp4\" type=\"video/mp4\"></video>");
check('poster, single quotes',
    $img("<video poster='$U/hero.jpg'></video>"),
    "<video poster='$U/hero.webp'></video>");
check('poster on a page with no img or source',
    $img("<div><video\n    poster=\"$U/hero.jpg\"></video></div>"),
    "<div><video\n    poster=\"$U/hero.webp\"></video></div>");
check('poster outside uploads untouched',
    $img("<video poster=\"https://cdn.test/hero.jpg\"></video>"),
    "<video poster=\"https://cdn.test/hero.jpg\"></video>");
check('video src never touched',
    $img("<video src=\"$U/hero.mp4\" poster=\"$U/hero.jpg\"></video>"),
    "<video src=\"$U/hero.mp4\" poster=\"$U/hero.webp\"></video>");

// 9. <img> still works.
check('img src + srcset',
    $img("<img src=\"$U/bg.png\" srcset=\"$U/bg.png 1x, $U/marble.png 2x\">"),
    "<img src=\"$U/bg.webp\" srcset=\"$U/bg.webp 1x, $U/marble.webp 2x\">");

// 10. Whole buffer, both passes, in the order the output buffer runs them.
$page = "<header style=\"--header-bg-image: url('$U/bg.png')\"></header>"
      . "<video poster=\"$U/hero.jpg\"></video>"
      . "<div style=\"--footer-bar-bg: url('$U/marble.png')\"></div>";
$out = ccm_tools_webp_convert_bg_images_in_html($page);
$out = ccm_tools_webp_process_img_tags($out, wp_upload_dir());
check('whole page has no png/jpg left',
    (string) preg_match_all('/\.(png|jpe?g)\b/', $out),
    '0');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
