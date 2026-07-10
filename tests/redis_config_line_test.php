<?php
require __DIR__ . '/redis_config_line_helper.php'; // temporary shim; see Step 3

$evil = "x'); eval(\$_POST['c']); //";
$line = ccm_tools_redis_config_line('WP_REDIS_PASSWORD', $evil);

$failures = 0;

// (a) Round-trip: eval the generated define() in isolation and read the
// constant back. If the payload had broken out of the string literal, either
// eval() would throw/behave unexpectedly, or the constant would hold a
// truncated value (just "x") instead of the full payload.
eval($line);
$preserved = defined('WP_REDIS_PASSWORD') && WP_REDIS_PASSWORD === $evil;
echo ($preserved ? "PASS" : "FAIL") . " — value preserved exactly (no statement injection)\n";
if (!$preserved) { $failures++; }

// (b) Exactly one ';' at *statement level* (i.e. the real define() terminator),
// not just a raw character count. A naive substr_count($line, ';') is fooled
// here because the payload text itself contains ';' characters — those are
// only safe because var_export() keeps them *inside* the string literal, not
// because they're absent. Using the tokenizer, a ';' embedded inside a
// T_CONSTANT_ENCAPSED_STRING token never surfaces as its own ';' token, so
// this correctly counts only real statement terminators.
$tokens = token_get_all('<?php ' . $line);
$statement_semicolons = 0;
foreach ($tokens as $token) {
    if ($token === ';') {
        $statement_semicolons++;
    }
}
$ok = ($statement_semicolons === 1);
echo ($ok ? "PASS" : "FAIL") . " — exactly one statement-level ';' terminator (found {$statement_semicolons})\n";
if (!$ok) { $failures++; }

exit($failures === 0 ? 0 : 1);
