<?php
/**
 * Every component modifier a page asks for must exist in the stylesheet.
 *
 * The component kit uses enum-like modifiers — `ccm-chip--good`,
 * `ccm-alert--warn`, `ccm-dot-bad` and so on. Asking for one that does not
 * exist is not an error anywhere: the browser keeps the unknown class, applies
 * nothing, and the element renders as bare text. There is no console warning,
 * `php -l` cannot see it, the render test still passes because the markup is
 * valid, and the nesting gate is happy.
 *
 * It shipped exactly that way: the Update channel panel picked its chip tone
 * from a variable that could be 'ok', but the kit defines --good/--warn/--bad/
 * --info. The healthy state lost its pill and rendered as grey text, while the
 * unhealthy one looked right, so the bug only appeared when things were going
 * well. Rik spotted it in a screenshot.
 *
 * This reads the source rather than running it, so it needs no WordPress.
 *
 * Run:  php tests/css_modifiers_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$css = (string) file_get_contents($root . '/css/style.css');
if ($css === '') {
    fwrite(STDERR, "could not read css/style.css\n");
    exit(2);
}

// Every class the stylesheet defines.
preg_match_all('/\.(ccm-[A-Za-z0-9_-]+)/', $css, $m);
$defined = array_flip($m[1]);

// Source that emits markup.
$files = array($root . '/ccm.php');
foreach (array_merge(glob($root . '/inc/*.php'), glob($root . '/js/*.js')) as $f) {
    $files[] = $f;
}

/*
 * The families worth checking are the ones where a wrong name degrades in
 * silence rather than breaking. A missing block class usually shows up as an
 * obviously unstyled page; a missing modifier just quietly does nothing.
 */
$patterns = array(
    '/\b(ccm-[a-z0-9]+(?:__[a-z0-9]+)?--[a-z0-9-]+)\b/',  // ccm-chip--good
    '/\b(ccm-dot-[a-z0-9-]+)\b/',                          // ccm-dot-bad
    '/\b(ccm-chip-[a-z0-9-]+)\b/',                         // ccm-chip-foo
);

$problems = array();
$checked  = 0;
$seen     = array();

foreach ($files as $file) {
    $src = (string) file_get_contents($file);

    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $src, $hits)) {
            continue;
        }
        foreach ($hits[1] as $class) {
            $key = $class . '@' . basename($file);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $checked++;

            if (!isset($defined[$class])) {
                $problems[$class][] = basename($file);
            }
        }
    }
}

/*
 * A modifier built by interpolation is invisible to the scan above, because
 * the class never appears whole in the source. Catch the common shape: a PHP
 * variable holding a tone that is concatenated onto a modifier prefix.
 */
$tone_families = array(
    'source_tone' => 'ccm-chip--',
);
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    foreach ($tone_families as $var => $prefix) {
        if (!preg_match_all('/\$' . preg_quote($var, '/') . '\s*=\s*[\'"]([a-z0-9-]+)[\'"]/', $src, $hits)) {
            continue;
        }
        foreach (array_unique($hits[1]) as $tone) {
            $checked++;
            if (!isset($defined[$prefix . $tone])) {
                $problems[$prefix . $tone][] = basename($file) . ' (via $' . $var . ')';
            }
        }
    }
}

printf("Component modifiers\n\n  %d modifier uses checked against css/style.css\n\n", $checked);

if ($problems) {
    foreach ($problems as $class => $where) {
        printf("  FAIL %-34s asked for in %s\n", $class, implode(', ', array_unique($where)));
    }
    printf("\n%d modifier(s) are not defined, so they render unstyled\n", count($problems));
    exit(1);
}

printf("  every modifier asked for is defined\n");
