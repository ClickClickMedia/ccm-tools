<?php
/**
 * Search engine visibility nag: who sees it, when a dismissal holds, and what
 * a dismissal sends.
 *
 * Loads the real inc/seo-nag.php against a handful of WordPress stubs and
 * drives its functions directly.
 *
 * Run:  php tests/seo_nag_test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('CCM_HELPER_ROOT_URL', 'https://example.test/wp-content/plugins/ccm-tools/');
define('CCM_HELPER_VERSION', 'test');

$GLOBALS['t_options'] = array();
$GLOBALS['t_home']    = 'https://staging.example.test';
$GLOBALS['t_admin']   = true;
$GLOBALS['t_is_admin_screen'] = true;
$GLOBALS['t_mail']    = array();
$GLOBALS['t_mail_ok'] = true;
$GLOBALS['t_actions'] = array();

class WP_Error {
    private $msg;
    public function __construct($code = '', $msg = '') { $this->msg = $msg; }
    public function get_error_message() { return $this->msg; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function esc_html_e($s, $d = null) { echo $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function add_action($hook, $fn, $p = 10, $a = 1) { $GLOBALS['t_actions'][$hook][] = $fn; }
function apply_filters($hook, $v) { return $v; }
function get_option($k, $default = false) { return array_key_exists($k, $GLOBALS['t_options']) ? $GLOBALS['t_options'][$k] : $default; }
function update_option($k, $v, $autoload = null) { $GLOBALS['t_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['t_options'][$k]); return true; }
function home_url() { return $GLOBALS['t_home']; }
function admin_url($p = '') { return $GLOBALS['t_home'] . '/wp-admin/' . $p; }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function is_admin() { return $GLOBALS['t_is_admin_screen']; }
function wp_doing_ajax() { return false; }
function is_network_admin() { return false; }
function ccm_tools_user_is_admin() { return $GLOBALS['t_admin']; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function get_bloginfo($k) { return 'Test &amp; Site'; }
function wp_specialchars_decode($s, $q = null) { return html_entity_decode((string) $s, ENT_QUOTES); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_date($f, $t = null) { return date($f, $t ?? time()); }
function wp_mail($to, $subject, $body, $headers = array()) {
    $GLOBALS['t_mail'][] = compact('to', 'subject', 'body', 'headers');
    return $GLOBALS['t_mail_ok'];
}

require dirname(__DIR__) . '/inc/seo-nag.php';

$user = (object) array(
    'ID' => 7, 'user_login' => 'camille', 'user_email' => 'camille@client.test', 'display_name' => 'Camille',
);

$fails = 0;
$n = 0;
function check(string $label, bool $ok): void {
    global $fails, $n;
    $n++;
    if ($ok) {
        printf("  ok   %s\n", $label);
    } else {
        $fails++;
        printf("  FAIL %s\n", $label);
    }
}
function reset_state(): void {
    $GLOBALS['t_options'] = array('blog_public' => '0');
    $GLOBALS['t_home']    = 'https://staging.example.test';
    $GLOBALS['t_admin']   = true;
    $GLOBALS['t_is_admin_screen'] = true;
    $GLOBALS['t_mail']    = array();
    $GLOBALS['t_mail_ok'] = true;
}

echo "Who sees it\n";
reset_state();
check('shows to an admin when search engines are blocked', ccm_tools_seo_nag_should_show());
$GLOBALS['t_options']['blog_public'] = '1';
check('hidden when search engines are allowed', !ccm_tools_seo_nag_should_show());
$GLOBALS['t_options']['blog_public'] = 0;
check('integer 0 still counts as blocked', ccm_tools_seo_nag_should_show());
unset($GLOBALS['t_options']['blog_public']);
check('missing option means public (core default)', !ccm_tools_seo_nag_should_show());
reset_state();
$GLOBALS['t_admin'] = false;
check('hidden from non-administrators', !ccm_tools_seo_nag_should_show());
reset_state();
$GLOBALS['t_is_admin_screen'] = false;
check('never on the front end', !ccm_tools_seo_nag_should_show());

echo "\nRendering\n";
reset_state();
ob_start();
ccm_tools_seo_nag_render();
$html = ob_get_clean();
check('banner rendered', strpos($html, 'id="ccmsn-banner"') !== false);
check('question is asked verbatim', strpos($html, 'Why are you dismissing this?') !== false);
foreach (array('landing_page', 'development', 'other') as $k) {
    check("offers $k", strpos($html, 'value="' . $k . '"') !== false);
}
check('render hook is in_admin_header (not stripped with notices)', in_array('ccm_tools_seo_nag_render', $GLOBALS['t_actions']['in_admin_header'] ?? array(), true));
$GLOBALS['t_options']['blog_public'] = '1';
ob_start();
ccm_tools_seo_nag_render();
check('renders nothing when public', ob_get_clean() === '');

echo "\nDismissing\n";
reset_state();
$r = ccm_tools_seo_nag_dismiss('', '', $user);
check('no reason is refused', is_wp_error($r));
$r = ccm_tools_seo_nag_dismiss('because', '', $user);
check('unknown reason is refused', is_wp_error($r));
$r = ccm_tools_seo_nag_dismiss('other', '', $user);
check('Other without a reason is refused', is_wp_error($r));
check('...and nothing was stored', get_option(CCM_TOOLS_SEO_NAG_OPTION) === false);
check('...and nothing was mailed', count($GLOBALS['t_mail']) === 0);

$r = ccm_tools_seo_nag_dismiss('landing_page', 'ignored', $user);
check('Landing Page dismisses', is_array($r) && !ccm_tools_seo_nag_should_show());
check('Landing Page sends no email', count($GLOBALS['t_mail']) === 0);
check('Landing Page keeps no detail', $r['detail'] === '');

reset_state();
$r = ccm_tools_seo_nag_dismiss('development', '', $user);
check('Development dismisses without email', is_array($r) && !ccm_tools_seo_nag_should_show() && count($GLOBALS['t_mail']) === 0);

reset_state();
$r = ccm_tools_seo_nag_dismiss('other', 'Client wants it hidden until launch on Friday', $user);
check('Other with a reason dismisses', is_array($r) && !ccm_tools_seo_nag_should_show());
check('Other sends exactly one email', count($GLOBALS['t_mail']) === 1);
$m = $GLOBALS['t_mail'][0] ?? array('to' => '', 'subject' => '', 'body' => '', 'headers' => array());
check('email goes to Rik', $m['to'] === 'rik@clickclickmedia.com.au');
check('email carries the reason', strpos($m['body'], 'Client wants it hidden until launch on Friday') !== false);
check('email names the site address', strpos($m['body'], 'https://staging.example.test') !== false);
check('email names the user', strpos($m['body'], 'camille') !== false);
check('replies go to the user', in_array('Reply-To: camille@client.test', $m['headers'], true));
check('mail outcome recorded', $r['mailed'] === true);

reset_state();
$GLOBALS['t_mail_ok'] = false;
$r = ccm_tools_seo_nag_dismiss('other', 'mail is broken here', $user);
check('dismissal stands when mail fails', is_array($r) && !ccm_tools_seo_nag_should_show());
check('failed mail recorded', $r['mailed'] === false);

echo "\nWhen a dismissal stops counting\n";
reset_state();
ccm_tools_seo_nag_dismiss('development', '', $user);
$GLOBALS['t_home'] = 'https://www.example.test';
check('site moved to a new address: nags again', ccm_tools_seo_nag_should_show());
$GLOBALS['t_home'] = 'https://staging.example.test/';
check('trailing slash difference is not a move', !ccm_tools_seo_nag_should_show());

reset_state();
ccm_tools_seo_nag_dismiss('landing_page', '', $user);
ccm_tools_seo_nag_on_visibility_change('0', '1');
check('unticking the box clears the dismissal', get_option(CCM_TOOLS_SEO_NAG_OPTION) === false);
$GLOBALS['t_options']['blog_public'] = '0';
check('ticking it again nags again', ccm_tools_seo_nag_should_show());

reset_state();
ccm_tools_seo_nag_dismiss('landing_page', '', $user);
ccm_tools_seo_nag_on_visibility_change('0', '0');
check('saving Reading with the box still ticked keeps the dismissal', !ccm_tools_seo_nag_should_show());

printf("\n%s: %d/%d\n", $fails ? 'FAILED' : 'all passed', $n - $fails, $n);
exit($fails ? 1 : 0);
