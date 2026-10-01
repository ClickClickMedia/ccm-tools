<?php
/**
 * Every health check's deep link must land on a page that exists here.
 *
 * The Site Health page links straight at individual settings on other pages,
 * as `...page=ccm-tools-redis#ccm-focus-redis-host`, and the target page
 * scrolls that element into view and highlights it. The whole point is that
 * "this is wrong" and "here is where you change it" are one click apart.
 *
 * Two ways that breaks, both silent:
 *
 *   1. The anchor does not exist. The page loads, nothing scrolls, nothing is
 *      highlighted, and it looks exactly like a link that simply went to the
 *      right page. Two of the first six written were wrong this way.
 *
 *   2. The PAGE does not exist. Half these screens register conditionally —
 *      Redis only with the extension, WebP only with an image library,
 *      Cloudflare only with its module, Front Page Debug only when
 *      CCM_DEBUG_FRONT_PAGE is defined. WordPress answers an unregistered
 *      slug with "Sorry, you are not allowed to access this page", which
 *      reads as a permissions fault and sends people looking in the wrong
 *      place entirely.
 *
 * The first version of this test checked (2) by asking whether the string
 * 'ccm-tools-debug' appeared anywhere in the plugin's PHP. It does — inside
 * the `if (defined('CCM_DEBUG_FRONT_PAGE'))` that stops it registering. The
 * test printed "ok" for a link that was answering 403 on a live site. So the
 * slugs here come from the registration calls themselves, parsed with PHP's
 * own tokeniser, along with whether each call sits inside a conditional.
 *
 * This reads the source rather than running it, so it needs no WordPress.
 *
 * Run:  php tests/health_links_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$health = (string) file_get_contents($root . '/inc/health.php');
if ($health === '') {
    fwrite(STDERR, "could not read inc/health.php\n");
    exit(2);
}

/**
 * Every admin page this plugin registers, and whether that registration is
 * conditional.
 *
 * Walks the tokens tracking which open braces belong to an `if`/`foreach`/etc,
 * so a registration nested inside one is recorded as conditional no matter how
 * the condition itself is written.
 *
 * @return array<string,bool> slug => is the registration conditional
 */
function ccm_registered_pages(string $code): array
{
    $tokens = token_get_all($code);
    $branch = array(T_IF, T_ELSEIF, T_ELSE, T_FOREACH, T_FOR, T_WHILE, T_SWITCH, T_TRY, T_CATCH);

    $stack   = array();   // one bool per open brace: does it belong to a branch?
    $pending = false;     // branch keyword seen, its brace not open yet
    $pages   = array();

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];

        if (is_array($t)) {
            if (in_array($t[0], $branch, true)) {
                $pending = true;
                continue;
            }

            if ($t[0] === T_STRING
                && ($t[1] === 'add_submenu_page' || $t[1] === 'add_menu_page')) {

                // Slug position differs: add_submenu_page takes a parent first.
                $want = ($t[1] === 'add_submenu_page') ? 4 : 3;

                $arg   = 0;
                $depth = 0;
                $slug  = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $u = $tokens[$j];
                    if ($u === '(') {
                        $depth++;
                        continue;
                    }
                    if ($u === ')') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                        continue;
                    }
                    if ($u === ',' && $depth === 1) {
                        $arg++;
                        continue;
                    }
                    if ($depth === 1 && $arg === $want
                        && is_array($u) && $u[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $slug = trim($u[1], "'\"");
                    }
                }

                if ($slug !== '') {
                    $conditional = in_array(true, $stack, true);
                    // An unconditional registration beats a conditional one.
                    if (!isset($pages[$slug]) || $pages[$slug] === true) {
                        $pages[$slug] = $conditional;
                    }
                }
            }
            continue;
        }

        if ($t === '{') {
            $stack[] = $pending;
            $pending = false;
            continue;
        }
        if ($t === '}') {
            array_pop($stack);
            continue;
        }
        if ($t === ';') {
            $pending = false;
            continue;
        }
    }

    return $pages;
}

$pages = ccm_registered_pages((string) file_get_contents($root . '/ccm.php'));

if (!$pages) {
    fwrite(STDERR, "no admin pages found — has the menu moved out of ccm.php?\n");
    exit(2);
}

// Does the link helper check, at run time, that the page it names was built?
$guarded = (bool) preg_match(
    '/function\s+ccm_tools_health_link\b.*?ccm_tools_health_page_exists\s*\(/s',
    $health
);

// Every anchor the health checks hand out. An empty page means the main screen.
preg_match_all(
    "/ccm_tools_health_link\(\s*'([a-z-]*)'\s*,\s*'([A-Za-z0-9_-]*)'/",
    $health,
    $links,
    PREG_SET_ORDER
);

if (!$links) {
    fwrite(STDERR, "no health links found — has the helper been renamed?\n");
    exit(2);
}

/*
 * Where an id can legitimately come from. Most are written in PHP; the
 * database task list is rendered by JavaScript, which builds its checkbox ids
 * as `opt-${item.key}` from the task keys the server sends, so those are
 * matched against the catalogue instead.
 */
$php = '';
foreach (array_merge(array($root . '/ccm.php'), glob($root . '/inc/*.php')) as $f) {
    $php .= (string) file_get_contents($f);
}
$js = '';
foreach (glob($root . '/js/*.js') as $f) {
    $js .= (string) file_get_contents($f);
}

/** Does an element with this id exist anywhere a page can render it? */
$id_exists = static function (string $id) use ($php, $js): string {
    if (strpos($php, 'id="' . $id . '"') !== false) {
        return 'php';
    }
    // JS-built ids, e.g. id="opt-${item.key}" on the database task list.
    if (strpos($id, 'opt-') === 0) {
        $key = substr($id, 4);
        if (strpos($js, 'id="opt-${item.key}"') !== false
            && preg_match("/'" . preg_quote($key, '/') . "'\s*=>/", $php)) {
            return 'js (task catalogue)';
        }
    }
    if (strpos($js, 'id="' . $id . '"') !== false || strpos($js, "id='" . $id . "'") !== false) {
        return 'js';
    }
    return '';
};

printf(
    "Health check deep links\n\n  %d link(s) declared, %d admin page(s) registered\n"
        . "  helper checks the page was built: %s\n\n",
    count($links),
    count($pages),
    $guarded ? 'yes' : 'NO'
);

$problems = array();
foreach ($links as $link) {
    list(, $page, $anchor) = $link;

    $slug = ($page === '') ? 'ccm-tools' : 'ccm-tools-' . $page;

    if (!array_key_exists($slug, $pages)) {
        $problems[] = array(
            $slug,
            $anchor,
            'nothing registers this page, so the link answers "not allowed to access this page"',
        );
        continue;
    }

    $note = '';
    if ($pages[$slug] === true) {
        if (!$guarded) {
            $problems[] = array(
                $slug,
                $anchor,
                'registered only inside a condition and the helper does not check, '
                    . 'so the link dies wherever that condition is false',
            );
            continue;
        }
        $note = '  (conditional page, link withheld when absent)';
    }

    if ($anchor === '') {
        printf("  ok   %-24s %-26s page only%s\n", $slug, '—', $note);
        continue;
    }

    $where = $id_exists($anchor);
    if ($where === '') {
        $problems[] = array($slug, $anchor, 'no element anywhere has this id, so the link scrolls nowhere');
        continue;
    }

    printf("  ok   %-24s %-26s from %s%s\n", $slug, '#' . $anchor, $where, $note);
}

printf("\n");
if ($problems) {
    foreach ($problems as $p) {
        printf("  FAIL %-24s %-26s %s\n", $p[0], '#' . $p[1], $p[2]);
    }
    printf("\n%d link(s) would land nowhere\n", count($problems));
    exit(1);
}

printf("  every link resolves to a page that exists and an element that exists\n");
