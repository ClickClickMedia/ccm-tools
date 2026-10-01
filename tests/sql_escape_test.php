<?php
/**
 * A LIKE ... ESCAPE clause must reach MySQL as ESCAPE '\\', never ESCAPE '\'.
 *
 * These queries match option names that contain underscores, and an
 * underscore is a single-character wildcard to LIKE. Escaping it needs an
 * ESCAPE clause naming the escape character, and naming a backslash in SQL
 * means writing it twice: ESCAPE '\\'. Written once, the backslash escapes
 * the closing quote instead, the string never ends, and MySQL rejects the
 * whole statement.
 *
 * PHP is the second layer. In a double-quoted PHP string "\\" is one
 * backslash, so the SQL needs "\\\\" in the source. The transient counter on
 * the Database page had two. Every call produced:
 *
 *     WordPress database error You have an error in your SQL syntax ...
 *     near '\\_site_transient\\_%' ESCAPE '\''
 *
 * $wpdb->get_var() returned null, the caller cast it to int, and the page
 * displayed "0 transients" — on every site, for as long as the code shipped.
 * Nothing in the interface suggested the number was anything but a count,
 * and the only trace was a line in debug.log that nobody reads unless
 * WP_DEBUG_LOG happens to be on.
 *
 * Counting backslashes by eye is exactly the thing not to rely on, so this
 * resolves each literal to the bytes MySQL would receive and checks those.
 *
 * Run:  php tests/sql_escape_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * The runtime value of a PHP string literal, as far as backslashes go.
 *
 * Interpolation is left alone: `{$wpdb->options}` holds no backslashes, and
 * only the ESCAPE clause is of interest here.
 */
function ccm_literal_value(string $raw, bool $bare = false): string
{
    if ($bare) {
        // A chunk from inside an interpolated string: no quotes to strip, and
        // double-quote escaping rules apply.
        $quote = '"';
        $body  = $raw;
    } else {
        $quote = $raw[0];
        $body  = substr($raw, 1, -1);
    }

    if ($quote === "'") {
        return str_replace(array("\\\\", "\\'"), array("\\", "'"), $body);
    }

    // Double quoted: only the backslash escape matters for this check.
    $out = '';
    for ($i = 0, $n = strlen($body); $i < $n; $i++) {
        if ($body[$i] === '\\' && $i + 1 < $n && $body[$i + 1] === '\\') {
            $out .= '\\';
            $i++;
            continue;
        }
        $out .= $body[$i];
    }
    return $out;
}

$files = array_merge(array($root . '/ccm.php'), glob($root . '/inc/*.php'));

$clauses  = 0;
$problems = array();

foreach ($files as $file) {
    $code = (string) file_get_contents($file);
    if (stripos($code, 'ESCAPE') === false) {
        continue;
    }

    foreach (token_get_all($code) as $token) {
        if (!is_array($token)) {
            continue;
        }

        /*
         * Two token types, because a literal carrying an interpolation is not
         * one string to the tokeniser but a run of chunks. The delete query
         * this counter has to agree with is written that way — its table name
         * comes from a variable in the same literal as the ESCAPE clause — so
         * checking only whole literals would quietly skip it and report a
         * clean run on half the clauses in the plugin.
         */
        $bare = ($token[0] === T_ENCAPSED_AND_WHITESPACE);
        if (!$bare && $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (strpos($token[1], 'ESCAPE') === false) {
            continue;
        }

        $sql = ccm_literal_value($token[1], $bare);

        if (!preg_match_all("/ESCAPE\s*'([^']*)'/", $sql, $hits, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($hits as $hit) {
            $clauses++;
            $escape = $hit[1];

            if ($escape === '\\\\') {
                printf(
                    "  ok   %-28s line %-5d ESCAPE '%s'\n",
                    basename($file),
                    $token[2],
                    $escape
                );
                continue;
            }

            $problems[] = array(
                basename($file),
                (int) $token[2],
                $escape === '\\'
                    ? "reaches MySQL as ESCAPE '\\', whose backslash escapes its own closing "
                        . "quote — the statement never terminates and the query is rejected"
                    : sprintf("reaches MySQL as ESCAPE '%s', which is not a single backslash", $escape),
            );
        }
    }
}

printf("SQL LIKE ... ESCAPE clauses\n\n");

if ($clauses === 0) {
    fwrite(STDERR, "no ESCAPE clauses found at all — if the transient queries were rewritten, "
        . "retarget this test rather than leaving it passing on nothing\n");
    exit(2);
}

printf("\n  %d clause(s) checked\n\n", $clauses);

if ($problems) {
    foreach ($problems as $p) {
        printf("  FAIL %-28s line %-5d %s\n", $p[0], $p[1], $p[2]);
    }
    printf("\n%d clause(s) would be rejected by MySQL\n", count($problems));
    exit(1);
}

printf("  every clause names a single backslash, the way MySQL needs it written\n");
