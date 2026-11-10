<?php
/**
 * Search engine visibility nag.
 *
 * When "Discourage search engines from indexing this site" is ticked in
 * Settings > Reading, every wp-admin page carries a big red banner saying so.
 * It can be dismissed, but only by answering why. "Other" needs a written
 * reason, and that reason is emailed to CCM.
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
            <p class="ccmsn-dialog__lead"><?php esc_html_e('Search engines will stay blocked. The banner comes back if the site moves to a new address, or if the setting is turned off and on again.', 'ccm-tools'); ?></p>

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
                <label for="ccmsn-detail"><?php esc_html_e('Tell us why (required). This is emailed to Click Click Media.', 'ccm-tools'); ?></label>
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
 * Record a dismissal and, for "Other", email the reason.
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
     * The dismissal stands even if mail fails. A site with broken mail would
     * otherwise be stuck with a banner it has already answered; whether the
     * email went is kept on the record instead.
     */
    if ($reason === 'other') {
        $record['mailed'] = ccm_tools_seo_nag_send_email($record, $user);
    }

    update_option(CCM_TOOLS_SEO_NAG_OPTION, $record, false);
    return $record;
}

function ccm_tools_seo_nag_send_email(array $record, $user): bool {
    $to = ccm_tools_seo_nag_recipient();
    if (!is_email($to)) {
        return false;
    }

    $site  = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $home  = home_url();
    $email = isset($user->user_email) ? (string) $user->user_email : '';
    $name  = isset($user->display_name) ? (string) $user->display_name : $record['user_login'];

    $subject = sprintf('[CCM Tools] Search engine block kept on %s', wp_parse_url($home, PHP_URL_HOST) ?: $home);

    $lines = array(
        'Someone dismissed the "search engines are blocked" banner and chose Other.',
        '',
        'Site:    ' . $site,
        'Address: ' . $home,
        'User:    ' . $name . ' (' . $record['user_login'] . ($email !== '' ? ', ' . $email : '') . ')',
        'When:    ' . wp_date('j M Y g:i a T', $record['time']),
        '',
        'Their reason:',
        $record['detail'],
        '',
        'Settings > Reading: ' . admin_url('options-reading.php'),
    );

    $headers = array();
    if ($email !== '' && is_email($email)) {
        $headers[] = 'Reply-To: ' . $email;
    }

    return (bool) wp_mail($to, $subject, implode("\n", $lines), $headers);
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
