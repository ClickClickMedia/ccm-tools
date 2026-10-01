<?php
/**
 * Every health check's deep link must land on something that exists.
 *
 * The Site Health page links straight at individual settings on other pages,
 * as `...page=ccm-tools-redis#ccm-focus-redis-host`, and the target page
 * scrolls that element into view and highlights it. The whole point is that
 * "this is wrong" and "here is where you change it" are one click apart.
 *
 * A link whose anchor does not exist fails in the quietest way available: the
 * page loads, nothing scrolls, nothing is highlighted, and it looks exactly
 * like a link that simply went to the right page. Two of the first six written
 * were wrong this way — `redis-connection` and `redis-dropin` were plausible
 * names for ids that were never there.
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

// Every anchor the health checks hand out.
preg_match_all(
    "/ccm_tools_health_link\(\s*'([a-z-]+)'\s*,\s*'([A-Za-z0-9_-]*)'/",
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
    if (strpos($js, "id=\"" . $id . "\"") !== false || strpos($js, "id='" . $id . "'") !== false) {
        return 'js';
    }
    return '';
};

// The admin page slugs that actually exist.
preg_match_all("/'(ccm-tools-[a-z-]+)'/", $php, $slug_hits);
$slugs = array_flip($slug_hits[1]);

printf("Health check deep links\n\n  %d link(s) declared\n\n", count($links));

$problems = array();
foreach ($links as $link) {
    list(, $page, $anchor) = $link;

    $slug = 'ccm-tools-' . $page;
    if (!isset($slugs[$slug])) {
        $problems[] = array($slug, $anchor, 'no admin page with that slug');
        continue;
    }

    if ($anchor === '') {
        printf("  ok   %-24s %-26s (page only)\n", $slug, '—');
        continue;
    }

    $where = $id_exists($anchor);
    if ($where === '') {
        $problems[] = array($slug, $anchor, 'no element anywhere has this id, so the link scrolls nowhere');
        continue;
    }

    printf("  ok   %-24s %-26s from %s\n", $slug, '#' . $anchor, $where);
}

printf("\n");
if ($problems) {
    foreach ($problems as $p) {
        printf("  FAIL %-24s %-26s %s\n", $p[0], '#' . $p[1], $p[2]);
    }
    printf("\n%d link(s) would land nowhere\n", count($problems));
    exit(1);
}

printf("  every link resolves to a page and an element that exists\n");
