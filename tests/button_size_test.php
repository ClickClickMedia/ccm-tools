<?php
/**
 * Button size is a property of the place, not of the page.
 *
 * There are two sizes: the default, and `.ccm-button-small`. Which one a
 * button gets should depend only on the kind of container it sits in, so that
 * nine admin screens read as one plugin. That had drifted in two places, both
 * found by eye rather than by anything here:
 *
 *   - .htaccess's "Remove CCM block" was the only small button in any hero
 *     action bar. It sat beside a full-size "Update .htaccess", so the two
 *     did not line up, which is what gave it away.
 *   - The dashboard's four setting-row controls were full size, where the
 *     same row component on Cloudflare and on every health check uses small.
 *
 * Three rules, checked against the source:
 *
 *   A. Every button in a .ccm-hero__actions bar is full size. Secondary and
 *      danger ones too -- Refresh, Flush Cache, Disable Object Cache are all
 *      full size, so size is not carrying the hierarchy there, tone is.
 *   B. Every button inside a .ccm-opt setting row is small.
 *   C. No two buttons sitting next to each other are different sizes. This is
 *      the one a person actually sees, because their edges fail to align.
 *
 * The save bar is the single exception to C and it is deliberate: a quiet
 * Discard beside a prominent Save, written identically on all four pages that
 * have one. It is listed below so that it stays a decision rather than
 * becoming the precedent for the next mismatched pair.
 *
 * Run:  php tests/button_size_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$files = array_merge(array($root . '/ccm.php'), glob($root . '/inc/*.php'));

/** The size a class list resolves to. */
function ccm_btn_size(string $classes): string
{
    return strpos($classes, 'ccm-button-small') !== false ? 'small' : 'full';
}

/** Every button in a file, as [line, size, classes, label]. */
function ccm_buttons(array $lines): array
{
    $out = array();
    foreach ($lines as $i => $line) {
        if (!preg_match('/<(?:button|a)\b[^>]*class="([^"]*\bccm-button\b[^"]*)"/', $line, $m)) {
            continue;
        }
        $label = '';
        for ($look = 0; $look < 3; $look++) {
            if (!isset($lines[$i + $look])) {
                break;
            }
            if (preg_match("/_e\('([^']{2,40})|__\('([^']{2,40})|>\s*([A-Za-z][A-Za-z .&]{2,30})</",
                           $lines[$i + $look], $lm)) {
                // Only the groups up to the one that matched are populated, so
                // a match on the first alternative leaves $lm[2] and $lm[3] unset.
                $label = '';
                foreach (array(3, 2, 1) as $g) {
                    if (isset($lm[$g]) && $lm[$g] !== '') {
                        $label = trim($lm[$g]);
                        break;
                    }
                }
                break;
            }
        }
        $out[] = array($i + 1, ccm_btn_size($m[1]), $m[1], $label);
    }
    return $out;
}

/**
 * The line a container closes on, by indentation.
 *
 * A flat "look ahead N lines" window is not good enough: it walked straight
 * past the end of the hero bar and claimed a button two containers later was
 * a hero action, and past the end of a setting row onto a section's action
 * row. Both were reported as faults against markup that was correct. So the
 * block ends at the first closing tag indented no further than its opening.
 */
function ccm_block_end(array $lines, int $start): int
{
    $indent = strlen($lines[$start]) - strlen(ltrim($lines[$start]));

    for ($i = $start + 1; $i < count($lines) && $i < $start + 60; $i++) {
        $trimmed = ltrim($lines[$i]);
        if ($trimmed === '' || strpos($trimmed, '</') !== 0) {
            continue;
        }
        if ((strlen($lines[$i]) - strlen($trimmed)) <= $indent) {
            return $i;
        }
    }
    return min($start + 30, count($lines) - 1);
}

$problems = array();
$counts   = array('hero' => 0, 'row' => 0, 'pairs' => 0);

foreach ($files as $file) {
    $name  = basename($file);
    $lines = explode("\n", (string) file_get_contents($file));
    $btns  = ccm_buttons($lines);

    // ── A. Hero action bars ────────────────────────────────────
    foreach ($lines as $i => $line) {
        if (strpos($line, 'ccm-hero__actions') === false) {
            continue;
        }
        $end = ccm_block_end($lines, $i);
        foreach ($btns as $b) {
            if ($b[0] <= $i + 1 || $b[0] > $end) {
                continue;
            }
            $counts['hero']++;
            if ($b[1] !== 'full') {
                $problems[] = array($name, $b[0],
                    sprintf('hero action "%s" is small; every other hero button is full size', $b[3]));
            }
        }
    }

    // ── B. Setting rows ────────────────────────────────────────
    foreach ($lines as $i => $line) {
        if (strpos($line, 'class="ccm-opt__main"') === false) {
            continue;
        }
        $end = ccm_block_end($lines, $i);
        foreach ($btns as $b) {
            if ($b[0] <= $i + 1 || $b[0] > $end) {
                continue;
            }
            // The save bar and section action rows are not setting rows.
            if (strpos($b[2], 'ccm-savebar__proxy') !== false) {
                continue;
            }
            $counts['row']++;
            if ($b[1] !== 'small') {
                $problems[] = array($name, $b[0],
                    sprintf('setting-row control "%s" is full size; rows use small everywhere else', $b[3]));
            }
        }
    }

    // ── C. Adjacent buttons ────────────────────────────────────
    for ($k = 0; $k + 1 < count($btns); $k++) {
        $a = $btns[$k];
        $b = $btns[$k + 1];

        if ($b[0] - $a[0] > 8) {
            continue;   // far enough apart to be in different rows
        }

        $closers = 0;
        for ($j = $a[0]; $j < $b[0] - 1; $j++) {
            if (isset($lines[$j]) && preg_match('#</(?:div|section|td|tr|details|p)>#', $lines[$j])) {
                $closers++;
            }
        }
        if ($closers > 1) {
            continue;   // a container closed between them
        }

        // The deliberate exception: a quiet Discard beside a prominent Save.
        if (strpos($a[2], 'data-savebar') !== false || strpos($b[2], 'data-savebar') !== false
            || stripos($a[3], 'discard') !== false) {
            continue;
        }

        $counts['pairs']++;
        if ($a[1] !== $b[1]) {
            $problems[] = array($name, $a[0],
                sprintf('"%s" (%s) sits beside "%s" (%s), so their edges do not line up',
                        $a[3], $a[1], $b[3], $b[1]));
        }
    }
}

// Two overlapping blocks can reach the same button; report it once.
$problems = array_values(array_unique($problems, SORT_REGULAR));

printf("Button size by context\n\n");
printf("  %d hero action button(s), %d setting-row control(s), %d adjacent pair(s) checked\n\n",
    $counts['hero'], $counts['row'], $counts['pairs']);

if ($counts['hero'] === 0 || $counts['row'] === 0) {
    fwrite(STDERR, "found no hero buttons or no setting-row controls — the markup has moved, "
        . "so retarget this test rather than leaving it passing on nothing\n");
    exit(2);
}

if ($problems) {
    foreach ($problems as $p) {
        printf("  FAIL %-26s line %-5d %s\n", $p[0], $p[1], $p[2]);
    }
    printf("\n%d button(s) are the wrong size for where they sit\n", count($problems));
    exit(1);
}

printf("  every button matches the size its container calls for\n");
