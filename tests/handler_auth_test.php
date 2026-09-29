<?php
/**
 * Every ajax handler must check a nonce and a capability.
 *
 * Each `wp_ajax_` action is reachable by any logged-in user who knows the
 * action name, so these two checks are the only thing between a subscriber and
 * whatever the handler does. Adding a handler and forgetting one of them is a
 * single-line mistake that no lint and no render test can see, and it does not
 * announce itself: the feature works perfectly for the administrator who wrote
 * it.
 *
 * This reads the source rather than running it, so it needs no WordPress.
 *
 * Run:  php tests/handler_auth_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$files = array($root . '/ccm.php');
foreach (glob($root . '/inc/*.php') as $f) {
    $files[] = $f;
}

$src = array();
foreach ($files as $f) {
    $src[basename($f)] = (string) file_get_contents($f);
}

/**
 * Return a function's full body by brace matching.
 *
 * @param array  $src  filename => source.
 * @param string $name Function name.
 * @return array{0: string|null, 1: string|null}
 */
function ccm_fn_body(array $src, string $name): array {
    foreach ($src as $file => $s) {
        $i = strpos($s, 'function ' . $name);
        if ($i === false) {
            continue;
        }
        $open = strpos($s, '{', $i);
        if ($open === false) {
            continue;
        }
        $depth = 0;
        for ($k = $open, $len = strlen($s); $k < $len; $k++) {
            if ($s[$k] === '{') {
                $depth++;
            } elseif ($s[$k] === '}') {
                $depth--;
                if ($depth === 0) {
                    return array(substr($s, $i, $k - $i + 1), $file);
                }
            }
        }
    }
    return array(null, null);
}

$registered = array();
foreach ($src as $file => $s) {
    if (preg_match_all(
        "/add_action\(\s*'wp_ajax_(nopriv_)?([a-z0-9_]+)'\s*,\s*'([a-z0-9_]+)'/",
        $s, $m, PREG_SET_ORDER
    )) {
        foreach ($m as $hit) {
            $registered[$hit[2]] = array(
                'fn' => $hit[3],
                'file' => $file,
                'nopriv' => $hit[1] !== '',
            );
        }
    }
}

ksort($registered);

$problems = array();
foreach ($registered as $action => $meta) {
    list($body, $where) = ccm_fn_body($src, $meta['fn']);

    if ($body === null) {
        $problems[] = array($action, $meta['fn'], 'handler function is registered but does not exist');
        continue;
    }

    $nonce = strpos($body, 'check_ajax_referer') !== false
        || strpos($body, 'wp_verify_nonce') !== false
        || strpos($body, 'check_admin_referer') !== false;
    /*
     * Since 8.9.0 the gate is ccm_tools_user_is_admin(), which requires the
     * administrator role and not merely the manage_options capability. A bare
     * current_user_can() still counts as a check here, because a handler may
     * legitimately test some other capability, but nothing in this plugin
     * should be reachable on manage_options alone.
     */
    $cap = strpos($body, 'ccm_tools_user_is_admin') !== false
        || strpos($body, 'current_user_can') !== false;
    $weak = strpos($body, "current_user_can('manage_options')") !== false;

    if ($meta['nopriv']) {
        // Nothing in this plugin should be reachable by a logged-out visitor.
        $problems[] = array($action, $meta['fn'], 'registered as wp_ajax_nopriv_, so any visitor can call it');
    } elseif (!$nonce && !$cap) {
        $problems[] = array($action, $meta['fn'], 'no nonce check and no capability check');
    } elseif (!$nonce) {
        $problems[] = array($action, $meta['fn'], 'no nonce check, so it is open to CSRF');
    } elseif (!$cap) {
        $problems[] = array($action, $meta['fn'], 'no capability check, so any logged-in user can call it');
    } elseif ($weak) {
        $problems[] = array($action, $meta['fn'], "gated on manage_options alone; use ccm_tools_user_is_admin()");
    }
}

/*
 * Page callbacks.
 *
 * Hiding the menu is not a gate. add_menu_page()/add_submenu_page() register
 * every screen with the capability string passed to them, and WordPress checks
 * THAT when the page is requested, independently of whether the menu item was
 * ever drawn. So a role holding manage_options but not the administrator role
 * saw no CCM Tools menu and could still open any of its pages by typing the
 * address.
 *
 * Two of eleven callbacks were missing their check when this was written: the
 * WebP and Cloudflare screens rendered their full settings and connection
 * state to anyone who guessed the URL. Neither existing test looked here at
 * all - this one knew only about wp_ajax_ registrations, and admin_gate_test
 * exercised the gate function in isolation - so the gap sat in the blind spot
 * between them.
 */
$page_callbacks = array();
foreach ($src as $file => $body) {
    if (preg_match_all('/add_(?:menu|submenu)_page\s*\((.*?)\);/s', $body, $calls)) {
        foreach ($calls[1] as $args) {
            if (preg_match("/array\(\s*\$this\s*,\s*'([a-z0-9_]+)'/i", $args, $m)) {
                $page_callbacks[$m[1]] = $file;
            } elseif (preg_match_all("/'([a-z0-9_]+)'/i", $args, $m)) {
                foreach ($m[1] as $cand) {
                    if (strpos($cand, 'render') !== false || strpos($cand, '_page') !== false) {
                        $page_callbacks[$cand] = $file;
                    }
                }
            }
        }
    }
}

$page_problems = array();
foreach ($page_callbacks as $fn => $where) {
    list($body, ) = ccm_fn_body($src, $fn);
    if ($body === null) {
        continue;   // a capability string or slug that merely looked like one
    }
    /*
     * The gate has to be near the top. A check at the bottom of a render
     * function has already leaked the page, so look only at the opening.
     */
    if (strpos(substr($body, 0, 900), 'ccm_tools_user_is_admin') === false) {
        $page_problems[] = array($fn, basename($where), 'no gate at the top of the callback');
    }
}

printf("Page callback authorisation" . PHP_EOL . PHP_EOL . "  %d callbacks found" . PHP_EOL . PHP_EOL, count($page_callbacks));
if ($page_problems) {
    foreach ($page_problems as $p) {
        printf("  FAIL %-38s %-28s %s" . PHP_EOL, $p[0], $p[1], $p[2]);
    }
    printf(PHP_EOL . "%d page callback(s) render without the gate, reachable by URL" . PHP_EOL, count($page_problems));
    exit(1);
}
printf("  every page callback gates before it renders" . PHP_EOL . PHP_EOL);

printf("Ajax handler authorisation\n\n  %d actions registered\n\n", count($registered));

if ($problems) {
    foreach ($problems as $p) {
        printf("  FAIL %-40s %-42s %s\n", $p[0], $p[1], $p[2]);
    }
    printf("\n%d handler(s) are not properly guarded\n", count($problems));
    exit(1);
}

printf("  every handler checks both a nonce and a capability\n");
