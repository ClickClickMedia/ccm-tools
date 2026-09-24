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
    $cap = strpos($body, 'current_user_can') !== false;

    if ($meta['nopriv']) {
        // Nothing in this plugin should be reachable by a logged-out visitor.
        $problems[] = array($action, $meta['fn'], 'registered as wp_ajax_nopriv_, so any visitor can call it');
    } elseif (!$nonce && !$cap) {
        $problems[] = array($action, $meta['fn'], 'no nonce check and no capability check');
    } elseif (!$nonce) {
        $problems[] = array($action, $meta['fn'], 'no nonce check, so it is open to CSRF');
    } elseif (!$cap) {
        $problems[] = array($action, $meta['fn'], 'no capability check, so any logged-in user can call it');
    }
}

printf("Ajax handler authorisation\n\n  %d actions registered\n\n", count($registered));

if ($problems) {
    foreach ($problems as $p) {
        printf("  FAIL %-40s %-42s %s\n", $p[0], $p[1], $p[2]);
    }
    printf("\n%d handler(s) are not properly guarded\n", count($problems));
    exit(1);
}

printf("  every handler checks both a nonce and a capability\n");
