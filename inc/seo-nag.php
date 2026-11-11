<?php
/**
 * Search engine visibility nag.
 *
 * When "Discourage search engines from indexing this site" is ticked in
 * Settings > Reading, every wp-admin page carries a big red banner saying so.
 * It can be dismissed, but only by answering why, and every answer is emailed
 * to CCM. "Other" also needs a written reason.
 *
 * A dismissal belongs to the site at one address. It is void when:
 *   - the site moves (home_url changes), so a staging copy that was dismissed
 *     as "Development" nags again the moment it is migrated to the live domain
 *     with the box still ticked;
 *   - the box is unticked, so ticking it again later starts a fresh nag.
 */

if (!defined('ABSPATH')) {
    exit;
}

const CCM_TOOLS_SEO_NAG_OPTION = 'ccm_tools_seo_nag_dismissal';

/**
 * Who hears about an "Other" dismissal. Override in wp-config.php with
 * CCM_TOOLS_SEO_NAG_EMAIL, or with the ccm_tools_seo_nag_email filter.
 */
function ccm_tools_seo_nag_recipient(): string {
    $to = defined('CCM_TOOLS_SEO_NAG_EMAIL') ? (string) CCM_TOOLS_SEO_NAG_EMAIL : 'rik@clickclickmedia.com.au';
    return (string) apply_filters('ccm_tools_seo_nag_email', $to);
}

/** The allowed answers, key => label. */
function ccm_tools_seo_nag_reasons(): array {
    return array(
        'landing_page' => __('Landing Page', 'ccm-tools'),
        'development'  => __('Development', 'ccm-tools'),
        'other'        => __('Other', 'ccm-tools'),
    );
}

/** True when the Reading setting is blocking search engines. */
function ccm_tools_seo_nag_is_blocking(): bool {
    return (string) get_option('blog_public', '1') === '0';
}

/** The stored dismissal, or null if there is none or it no longer applies. */
function ccm_tools_seo_nag_active_dismissal(): ?array {
    $d = get_option(CCM_TOOLS_SEO_NAG_OPTION);
    if (!is_array($d) || empty($d['reason']) || !isset($d['home_url'])) {
        return null;
    }
    if (untrailingslashit((string) $d['home_url']) !== untrailingslashit((string) home_url())) {
        return null;
    }
    return $d;
}

/** Should the current request draw the banner? */
function ccm_tools_seo_nag_should_show(): bool {
    if (!is_admin() || wp_doing_ajax()) {
        return false;
    }
    if (function_exists('is_network_admin') && is_network_admin()) {
        return false;
    }
    if (!function_exists('ccm_tools_user_is_admin') || !ccm_tools_user_is_admin()) {
        return false;
    }
    if (!ccm_tools_seo_nag_is_blocking()) {
        return false;
    }
    return ccm_tools_seo_nag_active_dismissal() === null;
}

add_action('admin_enqueue_scripts', 'ccm_tools_seo_nag_enqueue');
function ccm_tools_seo_nag_enqueue(): void {
    if (!ccm_tools_seo_nag_should_show()) {
        return;
    }
    wp_enqueue_style('ccm-tools-seo-nag', CCM_HELPER_ROOT_URL . 'css/seo-nag.css', array(), CCM_HELPER_VERSION);
    wp_enqueue_script('ccm-tools-seo-nag', CCM_HELPER_ROOT_URL . 'js/seo-nag.js', array(), CCM_HELPER_VERSION, true);
    wp_localize_script('ccm-tools-seo-nag', 'ccmSeoNag', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('ccm_tools_seo_nag'),
        'failed'  => __('Could not save that. Please try again.', 'ccm-tools'),
    ));
}

/*
 * in_admin_header rather than admin_notices: CCM Tools' own pages strip every
 * admin notice, as do plenty of other plugins, and core's common.js relocates
 * anything classed as a notice. This hook fires at the top of #wpcontent on
 * every admin screen and nobody strips it.
 */
add_action('in_admin_header', 'ccm_tools_seo_nag_render');
function ccm_tools_seo_nag_render(): void {
    if (!ccm_tools_seo_nag_should_show()) {
        return;
    }

    $reading = admin_url('options-reading.php#blog_public');
    $reasons = ccm_tools_seo_nag_reasons();
    ?>
    <div class="ccmsn-banner" id="ccmsn-banner" role="alert">
        <div class="ccmsn-banner__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M12 2 1 21h22L12 2Zm0 6c.6 0 1 .4 1 1v5a1 1 0 1 1-2 0V9c0-.6.4-1 1-1Zm0 9.2a1.3 1.3 0 1 1 0 2.6 1.3 1.3 0 0 1 0-2.6Z"/></svg>
        </div>
        <div class="ccmsn-banner__body">
            <p class="ccmsn-banner__title"><?php esc_html_e('Search engines are BLOCKED on this site', 'ccm-tools'); ?></p>
            <p class="ccmsn-banner__text">
                <?php esc_html_e('"Discourage search engines from indexing this site" is ticked in Settings > Reading. Google and every other search engine are being told to stay away, so this site will not show up in search results.', 'ccm-tools'); ?>
            </p>
        </div>
        <div class="ccmsn-banner__actions">
            <a class="ccmsn-btn ccmsn-btn--solid" href="<?php echo esc_url($reading); ?>"><?php esc_html_e('Fix it in Settings', 'ccm-tools'); ?></a>
            <button type="button" class="ccmsn-btn ccmsn-btn--ghost" id="ccmsn-dismiss"><?php esc_html_e('Dismiss', 'ccm-tools'); ?></button>
        </div>
    </div>

    <dialog class="ccmsn-dialog" id="ccmsn-dialog" aria-labelledby="ccmsn-dialog-title">
        <form method="dialog" class="ccmsn-dialog__form" id="ccmsn-form" novalidate>
            <h2 class="ccmsn-dialog__title" id="ccmsn-dialog-title"><?php esc_html_e('Why are you dismissing this?', 'ccm-tools'); ?></h2>
            <p class="ccmsn-dialog__lead"><?php esc_html_e('Search engines will stay blocked and your answer is sent to Click Click Media. The banner comes back if the site moves to a new address, or if the setting is turned off and on again.', 'ccm-tools'); ?></p>

            <fieldset class="ccmsn-choices">
                <legend class="screen-reader-text"><?php esc_html_e('Reason', 'ccm-tools'); ?></legend>
                <?php foreach ($reasons as $key => $label) : ?>
                    <label class="ccmsn-choice">
                        <input type="radio" name="ccmsn_reason" value="<?php echo esc_attr($key); ?>">
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="ccmsn-other" id="ccmsn-other" hidden>
                <label for="ccmsn-detail"><?php esc_html_e('Tell us why (required).', 'ccm-tools'); ?></label>
                <textarea id="ccmsn-detail" rows="4" maxlength="1000"></textarea>
            </div>

            <p class="ccmsn-error" id="ccmsn-error" role="alert" hidden></p>

            <div class="ccmsn-dialog__actions">
                <button type="button" class="ccmsn-btn ccmsn-btn--plain" id="ccmsn-cancel"><?php esc_html_e('Cancel', 'ccm-tools'); ?></button>
                <button type="submit" class="ccmsn-btn ccmsn-btn--danger" id="ccmsn-confirm" disabled><?php esc_html_e('Dismiss banner', 'ccm-tools'); ?></button>
            </div>
        </form>
    </dialog>
    <?php
}

add_action('wp_ajax_ccm_tools_seo_nag_dismiss', 'ccm_tools_seo_nag_ajax_dismiss');
function ccm_tools_seo_nag_ajax_dismiss(): void {
    check_ajax_referer('ccm_tools_seo_nag', 'nonce');
    if (!ccm_tools_user_is_admin()) {
        wp_send_json_error(array('message' => __('You do not have permission to do that.', 'ccm-tools')), 403);
    }

    $reason = isset($_POST['reason']) ? sanitize_key(wp_unslash($_POST['reason'])) : '';
    $detail = isset($_POST['detail']) ? sanitize_textarea_field(wp_unslash($_POST['detail'])) : '';
    $detail = trim(function_exists('mb_substr') ? mb_substr($detail, 0, 1000) : substr($detail, 0, 1000));

    $result = ccm_tools_seo_nag_dismiss($reason, $detail, wp_get_current_user());
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 400);
    }
    wp_send_json_success($result);
}

/**
 * Record a dismissal and email it to CCM.
 *
 * @return array|WP_Error The stored dismissal.
 */
function ccm_tools_seo_nag_dismiss(string $reason, string $detail, $user) {
    $reasons = ccm_tools_seo_nag_reasons();
    if (!isset($reasons[$reason])) {
        return new WP_Error('ccm_seo_nag_reason', __('Pick a reason.', 'ccm-tools'));
    }
    if ($reason === 'other' && $detail === '') {
        return new WP_Error('ccm_seo_nag_detail', __('Please say why you are dismissing this.', 'ccm-tools'));
    }
    if ($reason !== 'other') {
        $detail = '';
    }

    $record = array(
        'reason'     => $reason,
        'detail'     => $detail,
        'user_id'    => isset($user->ID) ? (int) $user->ID : 0,
        'user_login' => isset($user->user_login) ? (string) $user->user_login : '',
        'time'       => time(),
        'home_url'   => home_url(),
        'mailed'     => null,
    );

    /*
     * Every dismissal is emailed. It stands even if mail fails: a site with
     * broken mail would otherwise be stuck with a banner it has already
     * answered, so whether the email went is kept on the record instead.
     */
    $record['mailed'] = ccm_tools_seo_nag_send_email($record, $user);

    update_option(CCM_TOOLS_SEO_NAG_OPTION, $record, false);
    return $record;
}

/** The facts an email carries, shared by the HTML and plain-text bodies. */
function ccm_tools_seo_nag_email_facts(array $record, $user): array {
    $email = isset($user->user_email) ? (string) $user->user_email : '';
    $name  = isset($user->display_name) && $user->display_name !== '' ? (string) $user->display_name : $record['user_login'];
    $who   = $name . ' (' . $record['user_login'] . ($email !== '' ? ', ' . $email : '') . ')';

    return array(
        'Site'    => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
        'Address' => home_url(),
        'User'    => $who,
        'When'    => wp_date('j M Y g:i a T', $record['time']),
    );
}

/** "web.clickclick.media/_websites/riktest_2024": the host alone is shared by every preview site. */
function ccm_tools_seo_nag_short_address(): string {
    $home = home_url();
    $host = (string) wp_parse_url($home, PHP_URL_HOST);
    $path = untrailingslashit((string) wp_parse_url($home, PHP_URL_PATH));
    return $host !== '' ? $host . $path : $home;
}

function ccm_tools_seo_nag_send_email(array $record, $user): bool {
    $to = ccm_tools_seo_nag_recipient();
    if (!is_email($to)) {
        return false;
    }

    $reasons = ccm_tools_seo_nag_reasons();
    $label   = $reasons[$record['reason']] ?? $record['reason'];
    $facts   = ccm_tools_seo_nag_email_facts($record, $user);
    $reading = admin_url('options-reading.php');

    $subject = sprintf('[CCM Tools] Search engines kept blocked on %s - %s', ccm_tools_seo_nag_short_address(), $label);

    $html = ccm_tools_seo_nag_email_html($record, $label, $facts, $reading);

    $text = array(
        sprintf('Someone dismissed the "search engines are blocked" banner and chose %s.', $label),
        '',
    );
    foreach ($facts as $k => $v) {
        $text[] = str_pad($k . ':', 9) . $v;
    }
    if ($record['detail'] !== '') {
        $text[] = '';
        $text[] = 'Their reason:';
        $text[] = $record['detail'];
    }
    $text[] = '';
    $text[] = 'Settings > Reading: ' . $reading;
    $plain = implode("\n", $text);

    $headers = array('Content-Type: text/html; charset=UTF-8');
    $email   = isset($user->user_email) ? (string) $user->user_email : '';
    if ($email !== '' && is_email($email)) {
        $headers[] = 'Reply-To: ' . $email;
    }

    // Plain-text alternative for clients that will not render HTML. One shot,
    // so no other mail this request inherits it.
    $alt = function ($phpmailer) use ($plain) {
        $phpmailer->AltBody = $plain;
    };
    add_action('phpmailer_init', $alt);
    $sent = (bool) wp_mail($to, $subject, $html, $headers);
    remove_action('phpmailer_init', $alt);

    return $sent;
}

/**
 * CCM notification email, the same look as ClickTrack's and ServerWatch's:
 * dark header with the green dot, green accent line, 600px white card, blue
 * button, grey footer, with Outlook dark-mode guards and MSO fallbacks.
 */
function ccm_tools_seo_nag_email_html(array $record, string $label, array $facts, string $reading): string {
    $blue  = '#1a5ddf';
    $green = '#94c83e';
    // Amber for Other, because that is the one with something to read.
    $badge_color = $record['reason'] === 'other' ? '#d97706' : $blue;
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };
    $p = function ($html) {
        return '<p class="x-drk" style="margin:0 0 20px;color:#1f1f1f;font-size:15px;line-height:1.65;">' . $html . '</p>';
    };

    $title   = 'Search engines kept blocked';
    $preview = $label . ': ' . $facts['Site'] . ' (' . ccm_tools_seo_nag_short_address() . ')';

    $rows = '';
    foreach ($facts as $k => $v) {
        $value = $k === 'Address'
            ? '<a href="' . $e($v) . '" style="color:' . $blue . ';text-decoration:none;">' . $e($v) . '</a>'
            : $e($v);
        $rows .= '<tr>'
            . '<td valign="top" style="padding:8px 12px;border-bottom:1px solid #eeeeee;width:80px;"><span class="x-mut" style="color:#6f6f6f;font-size:13px;">' . $e($k) . '</span></td>'
            . '<td valign="top" style="padding:8px 12px;border-bottom:1px solid #eeeeee;"><span class="x-drk" style="color:#1f1f1f;font-size:14px;">' . $value . '</span></td>'
            . '</tr>';
    }
    $table = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border:1px solid #eeeeee;border-radius:6px;margin:0 0 20px;">' . $rows . '</table>';

    $quote = '';
    if ($record['detail'] !== '') {
        $quote = '<p class="x-mut" style="margin:4px 0 8px;color:#6f6f6f;font-size:12px;line-height:1.5;text-transform:uppercase;letter-spacing:0.6px;">Their reason</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#F6F6F6;border-radius:8px;"><tr>'
            . '<td class="x-drk" style="padding:20px 24px;border-left:3px solid ' . $badge_color . ';color:#1f1f1f;font-size:15px;line-height:1.7;">'
            . nl2br($e($record['detail']), false)
            . '</td></tr></table>';
    }

    $body = $p('Someone dismissed the <strong>search engines are blocked</strong> banner on <strong>' . $e($facts['Site']) . '</strong> and chose <strong>' . $e($label) . '</strong>. Search engines are still being told not to index the site.')
        . $table
        . $quote;

    $button = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:32px;"><tr><td align="center"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<!--[if mso]><td><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $e($reading) . '" style="height:46px;v-text-anchor:middle;width:240px;" arcsize="13%" strokecolor="' . $blue . '" fillcolor="' . $blue . '"><w:anchorlock/><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">Open Reading settings</center></v:roundrect></td><![endif]-->'
        . '<!--[if !mso]><!--><td><a class="x-wht x-btn" href="' . $e($reading) . '" style="display:inline-block;background-color:' . $blue . ';color:#ffffff;padding:14px 36px;border-radius:6px;text-decoration:none;font-weight:700;font-size:14px;text-align:center;mso-padding-alt:0;">Open Reading settings</a></td><!--<![endif]-->'
        . '</tr></table></td></tr></table>';

    return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="light only" />
<meta name="supported-color-schemes" content="light only" />
<title>' . $e($title) . '</title>
<style type="text/css">
:root { color-scheme: light only; supported-color-schemes: light only; }
[data-ogsb].x-hdr { background-color: #1f1f1f !important; }
[data-ogsb].x-btn { background-color: ' . $blue . ' !important; }
[data-ogsc].x-wht { color: #ffffff !important; }
[data-ogsc].x-drk { color: #1f1f1f !important; }
[data-ogsc].x-grn { color: ' . $green . ' !important; }
[data-ogsc].x-mut { color: #9f9f9f !important; }
@media (prefers-color-scheme: dark) {
  .x-hdr { background-color: #1f1f1f !important; }
  .x-btn { background-color: ' . $blue . ' !important; }
  .x-wht { color: #ffffff !important; }
  .x-drk { color: #1f1f1f !important; }
  .x-grn { color: ' . $green . ' !important; }
}
</style>
<!--[if mso]>
<style type="text/css">
body, table, td, p, a, span {font-family: Arial, Helvetica, sans-serif !important;}
table {border-collapse: collapse !important;}
</style>
<![endif]-->
</head>
<body style="margin:0;padding:0;width:100%;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#F6F6F6;-webkit-font-smoothing:antialiased;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">' . $e($preview) . '&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#F6F6F6;"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;">
<tr><td class="x-hdr" bgcolor="#1f1f1f" style="background-color:#1f1f1f;padding:28px 40px;border-radius:8px 8px 0 0;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
<td valign="middle"><span class="x-wht" style="font-size:20px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;"><span class="x-grn" style="color:' . $green . ';">&#9679;</span>&nbsp;CCM Tools</span></td>
<td align="right" valign="middle"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="' . $badge_color . '" style="background-color:' . $badge_color . ';padding:5px 16px;border-radius:4px;"><span class="x-wht" style="color:#ffffff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;">' . $e($label) . '</span></td></tr></table></td>
</tr></table></td></tr>
<tr><td style="background-color:' . $green . ';height:3px;font-size:3px;line-height:3px;">&nbsp;</td></tr>
<tr><td style="padding:32px 40px 36px;">
' . $body . '
' . $button . '
</td></tr>
<tr><td style="padding:24px 40px;background-color:#F6F6F6;border-top:1px solid #ebebeb;border-radius:0 0 8px 8px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td align="center">
<p style="margin:0;color:#9f9f9f;font-size:12px;line-height:1.5;">Sent by <span style="color:' . $green . ';font-weight:700;">CCM Tools</span> &middot; WordPress tools by Click Click Media</p>
</td></tr></table></td></tr>
</table></td></tr></table>
</body></html>';
}

/*
 * Unticking the box wipes the dismissal, so ticking it again later starts a
 * fresh nag rather than inheriting an answer given for a different reason.
 */
add_action('update_option_blog_public', 'ccm_tools_seo_nag_on_visibility_change', 10, 2);
function ccm_tools_seo_nag_on_visibility_change($old_value, $value): void {
    if ((string) $value !== '0') {
        delete_option(CCM_TOOLS_SEO_NAG_OPTION);
    }
}
