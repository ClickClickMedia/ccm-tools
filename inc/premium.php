<?php
/**
 * CCM Tools Premium — Subscription Management & Feature Gating
 *
 * Manages premium subscription status, feature access control,
 * and upgrade UI for CCM Tools.
 *
 * @package CCM_Tools
 * @since 7.20.0
 */

if (!defined('ABSPATH')) exit;

// ─── Constants ──────────────────────────────────────────────────

/** URL of the Premium website (pricing / checkout). Override in wp-config.php. */
if (!defined('CCM_TOOLS_PREMIUM_URL')) {
    define('CCM_TOOLS_PREMIUM_URL', 'https://premium.clickclickmedia.com.au');
}

/** Transient expiry for cached premium status (seconds). */
if (!defined('CCM_TOOLS_PREMIUM_CACHE_TTL')) {
    define('CCM_TOOLS_PREMIUM_CACHE_TTL', 12 * HOUR_IN_SECONDS);
}

// ─── Feature Definitions ────────────────────────────────────────

/**
 * Premium feature groups and their contents.
 *
 * @return array Keyed by feature slug.
 */
function ccm_tools_premium_features(): array {
    return array(
        'ai_performance' => array(
            'name'        => 'AI Performance Hub',
            'icon'        => '🤖',
            'description' => 'AI-powered PageSpeed analysis and automated optimization',
            'includes'    => array(
                'PageSpeed testing (Mobile & Desktop)',
                'AI-powered analysis with actionable recommendations',
                'One-click automated optimization with rollback safety',
                'Visual regression detection (before/after screenshots)',
                'Console error checking (headless Chromium)',
                'AI Troubleshooter chat assistant',
                'Cross-site optimization intelligence',
            ),
        ),
        'advanced_redis' => array(
            'name'        => 'Advanced Redis Configuration',
            'icon'        => '⚡',
            'description' => 'Enterprise-grade Redis object cache tuning',
            'includes'    => array(
                'Serializer selection (igbinary, msgpack)',
                'Compression (LZF, LZ4, Zstd)',
                'Async flush (non-blocking UNLINK)',
                'ACL authentication (Redis 6.0+)',
                'TLS/SSL encrypted connections',
                'WooCommerce Redis optimization',
                'Drop-in runtime diagnostics',
                'Connection & read timeout tuning',
            ),
        ),
    );
}

/**
 * Features included in the free tier.
 *
 * @return array List of feature descriptions.
 */
function ccm_tools_free_features(): array {
    return array(
        array('name' => 'System Information Dashboard', 'description' => 'Server environment, disk usage, memory limits, TTFB measurement'),
        array('name' => 'Database Tools',               'description' => 'InnoDB converter, table optimizer, collation updates'),
        array('name' => '.htaccess Optimization',        'description' => 'Caching, Gzip/Brotli, security headers, HSTS'),
        array('name' => 'Error Log Viewer',              'description' => 'Real-time log viewing, filtering, auto-refresh'),
        array('name' => 'Debug Mode Controls',           'description' => 'Toggle WP_DEBUG, logging, display'),
        array('name' => 'WebP Image Converter',          'description' => 'Automatic WebP conversion, picture tags, on-demand & bulk convert'),
        array('name' => 'Performance Optimizer',         'description' => 'Defer/delay JS, async CSS, critical CSS, resource hints, more'),
        array('name' => 'Basic Redis Object Cache',      'description' => 'Enable/disable, connect, flush, basic settings'),
        array('name' => 'WooCommerce Tools',             'description' => 'Admin payment toggle, store overview (when WooCommerce active)'),
    );
}

// ─── Status Checks ──────────────────────────────────────────────

/**
 * Check whether the current site has an active premium subscription.
 *
 * Order of precedence:
 *   1. `CCM_TOOLS_PREMIUM` constant (developer override)
 *   2. Transient cache
 *   3. Live API call to the hub
 *
 * @return bool
 */
function ccm_tools_is_premium(): bool {
    // 1. Developer override
    if (defined('CCM_TOOLS_PREMIUM')) {
        return (bool) CCM_TOOLS_PREMIUM;
    }

    // 2. Transient cache
    $cached = get_transient('ccm_tools_premium_status');
    if ($cached !== false) {
        return $cached === 'active';
    }

    // 3. No hub configured → not premium
    $hub_settings = get_option('ccm_tools_ai_hub_settings', array());
    if (empty($hub_settings['api_key'])) {
        set_transient('ccm_tools_premium_status', 'inactive', CCM_TOOLS_PREMIUM_CACHE_TTL);
        return false;
    }

    // 4. Live hub check
    $result = ccm_tools_premium_check_with_hub();
    $is_active = ($result && !empty($result['premium']));

    set_transient('ccm_tools_premium_status', $is_active ? 'active' : 'inactive', CCM_TOOLS_PREMIUM_CACHE_TTL);

    if ($result && is_array($result)) {
        set_transient('ccm_tools_premium_details', $result, CCM_TOOLS_PREMIUM_CACHE_TTL);
    }

    return $is_active;
}

/**
 * Check whether a specific premium feature group is available.
 *
 * @param string $feature_key  e.g. 'ai_performance' or 'advanced_redis'.
 * @return bool
 */
function ccm_tools_has_premium_feature(string $feature_key): bool {
    if (!ccm_tools_is_premium()) {
        return false;
    }

    $status = ccm_tools_premium_get_status();

    // If the hub returns an explicit feature list, respect it.
    if (!empty($status['features']) && is_array($status['features'])) {
        return in_array($feature_key, $status['features'], true);
    }

    // Otherwise all premium features are included.
    return true;
}

/**
 * Get detailed premium status (plan, expiry, features).
 *
 * @return array
 */
function ccm_tools_premium_get_status(): array {
    $default = array(
        'premium'     => false,
        'plan'        => '',
        'expires'     => '',
        'features'    => array(),
        'customer_id' => '',
    );

    // Developer override
    if (defined('CCM_TOOLS_PREMIUM') && CCM_TOOLS_PREMIUM) {
        return array_merge($default, array(
            'premium'  => true,
            'plan'     => 'developer',
            'features' => array_keys(ccm_tools_premium_features()),
        ));
    }

    $cached = get_transient('ccm_tools_premium_details');
    if ($cached !== false && is_array($cached)) {
        return wp_parse_args($cached, $default);
    }

    // Trigger a live check (which also caches)
    ccm_tools_is_premium();

    $cached = get_transient('ccm_tools_premium_details');
    return ($cached && is_array($cached)) ? wp_parse_args($cached, $default) : $default;
}

/**
 * Clear cached premium status (call after a payment event or manual refresh).
 */
function ccm_tools_premium_clear_cache(): void {
    delete_transient('ccm_tools_premium_status');
    delete_transient('ccm_tools_premium_details');
    delete_transient('ccm_tools_premium_pricing');
}

// ─── Hub Communication ──────────────────────────────────────────

/**
 * Query the API hub for premium subscription status.
 *
 * Uses the shared hub request function for consistent auth headers.
 * Expected hub response on success:
 *   { "success": true, "premium": true, "plan": "monthly", "expires": "2026-04-05T00:00:00Z",
 *     "features": [...], "price": { "amount": 4900, "currency": "aud", "formatted": "$49", "interval": "month" } }
 *
 * @return array|null  Parsed response data, or null on failure.
 */
function ccm_tools_premium_check_with_hub(): ?array {
    if (!function_exists('ccm_tools_ai_hub_request')) {
        return null;
    }

    $result = ccm_tools_ai_hub_request('premium/status', [], 'GET', 15);

    if (is_wp_error($result) || !is_array($result)) {
        return null;
    }

    // Hub returns flat: { success: true, premium: true, ... } or { premium: true, ... }
    if (!empty($result['success']) || isset($result['premium'])) {
        // Cache pricing data separately if present
        if (!empty($result['price']) && is_array($result['price'])) {
            set_transient('ccm_tools_premium_pricing', $result['price'], CCM_TOOLS_PREMIUM_CACHE_TTL);
        }
        return $result;
    }

    return null;
}

/**
 * Get the current Stripe subscription price.
 *
 * Pulls from cached pricing data (populated by the premium status check).
 * Falls back to a dedicated pricing endpoint if no cached data.
 *
 * @return array { amount: int (cents), currency: string, formatted: string, interval: string } or empty.
 */
function ccm_tools_premium_get_pricing(): array {
    $default = array(
        'amount'    => 0,
        'currency'  => 'aud',
        'formatted' => '',
        'interval'  => 'month',
    );

    // 1. Check transient cache
    $cached = get_transient('ccm_tools_premium_pricing');
    if ($cached && is_array($cached) && !empty($cached['formatted'])) {
        return wp_parse_args($cached, $default);
    }

    // 2. Try fetching from hub pricing endpoint
    if (function_exists('ccm_tools_ai_hub_request')) {
        $result = ccm_tools_ai_hub_request('premium/pricing', [], 'GET', 10);
        if (!is_wp_error($result) && is_array($result) && !empty($result['formatted'])) {
            set_transient('ccm_tools_premium_pricing', $result, CCM_TOOLS_PREMIUM_CACHE_TTL);
            return wp_parse_args($result, $default);
        }
    }

    return $default;
}

// ─── Checkout / Upgrade URL ─────────────────────────────────────

/**
 * Build the upgrade/checkout URL with site context parameters.
 *
 * @return string
 */
function ccm_tools_premium_get_checkout_url(): string {
    return add_query_arg(array(
        'utm_source' => 'plugin',
        'utm_medium' => 'upgrade-cta',
        'site'       => urlencode(function_exists('ccm_tools_normalize_site_url') ? ccm_tools_normalize_site_url(site_url()) : site_url()),
    ), trailingslashit(CCM_TOOLS_PREMIUM_URL));
}

/**
 * Build the account / manage-subscription URL.
 *
 * @return string
 */
function ccm_tools_premium_get_account_url(): string {
    return trailingslashit(CCM_TOOLS_PREMIUM_URL) . 'account';
}

// ─── Render Helpers ─────────────────────────────────────────────

/**
 * Render the premium badge for the header nav bar.
 *
 * @return string HTML string.
 */
function ccm_tools_render_premium_badge(): string {
    if (ccm_tools_is_premium()) {
        return '<span class="ccm-premium-badge ccm-premium-badge-pro" title="Premium subscription active">Premium</span>';
    }
    $url = ccm_tools_premium_get_checkout_url();
    return '<a href="' . esc_url($url) . '" class="ccm-premium-badge ccm-premium-badge-free" target="_blank" rel="noopener" title="Upgrade to CCM Tools Premium">Upgrade</a>';
}

/**
 * Render a full upsell card for a locked feature group.
 *
 * @param string $feature_key  Key into ccm_tools_premium_features().
 * @param bool   $compact      Render a one-line compact variant.
 */
function ccm_tools_render_premium_upsell(string $feature_key, bool $compact = false): void {
    $features = ccm_tools_premium_features();
    if (!isset($features[$feature_key])) return;

    $feature     = $features[$feature_key];
    $checkout_url = ccm_tools_premium_get_checkout_url();

    if ($compact) { ?>
        <div class="ccm-premium-upsell-compact">
            <span class="ccm-premium-lock-icon">🔒</span>
            <span class="ccm-premium-upsell-text">
                <strong><?php echo esc_html($feature['name']); ?></strong> requires
                <a href="<?php echo esc_url($checkout_url); ?>" target="_blank" rel="noopener">CCM&nbsp;Tools&nbsp;Premium</a>
            </span>
        </div>
    <?php return; } ?>

    <div class="ccm-premium-upsell-card">
        <div class="ccm-premium-upsell-header">
            <span class="ccm-premium-upsell-icon"><?php echo esc_html($feature['icon']); ?></span>
            <div>
                <h3><?php echo esc_html($feature['name']); ?></h3>
                <p><?php echo esc_html($feature['description']); ?></p>
            </div>
        </div>
        <div class="ccm-premium-upsell-features">
            <h4>Included with Premium:</h4>
            <ul>
                <?php foreach ($feature['includes'] as $item): ?>
                    <li><span class="ccm-check ccm-check-premium">✓</span> <?php echo esc_html($item); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="ccm-premium-upsell-cta">
            <a href="<?php echo esc_url($checkout_url); ?>" class="ccm-button ccm-button-premium" target="_blank" rel="noopener">
                Upgrade to Premium
            </a>
            <span class="ccm-premium-upsell-note">Billed monthly per site &middot; cancel anytime</span>
        </div>
    </div>
<?php }

/**
 * Render the Premium comparison table (Free vs Premium).
 */
function ccm_tools_render_premium_comparison(): void {
    // Never show comparison cards to premium subscribers
    if (ccm_tools_is_premium()) {
        return;
    }

    $premium_features = ccm_tools_premium_features();
    $free_features    = ccm_tools_free_features();
    $checkout_url     = ccm_tools_premium_get_checkout_url();
    $pricing          = ccm_tools_premium_get_pricing();
    $price_display    = !empty($pricing['formatted']) ? esc_html($pricing['formatted']) : '$49';
    $price_interval   = !empty($pricing['interval']) ? esc_html($pricing['interval']) : 'month';
    ?>
    <div class="ccm-premium-comparison">
        <div class="ccm-premium-comparison-grid">
            <!-- Free Column -->
            <div class="ccm-premium-plan ccm-premium-plan-free">
                <div class="ccm-premium-plan-header">
                    <h4>Free</h4>
                    <div class="ccm-premium-plan-price">$0<span>/month</span></div>
                    <p>Essential WordPress tools</p>
                </div>
                <ul class="ccm-premium-plan-features">
                    <?php foreach ($free_features as $f): ?>
                        <li>
                            <span class="ccm-check">✓</span>
                            <span>
                                <strong><?php echo esc_html($f['name']); ?></strong>
                                <small><?php echo esc_html($f['description']); ?></small>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    <?php foreach ($premium_features as $pf): ?>
                        <li class="ccm-premium-locked">
                            <span class="ccm-cross">✗</span>
                            <span><?php echo esc_html($pf['name']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Premium Column -->
            <div class="ccm-premium-plan ccm-premium-plan-premium">
                <div class="ccm-premium-plan-header">
                    <span class="ccm-premium-rec-badge">Recommended</span>
                    <h4>Premium</h4>
                    <div class="ccm-premium-plan-price"><?php echo $price_display; ?><span>/<?php echo $price_interval; ?> per site</span></div>
                    <p>Full AI &amp; Redis power</p>
                </div>
                <ul class="ccm-premium-plan-features">
                    <?php foreach ($free_features as $f): ?>
                        <li>
                            <span class="ccm-check">✓</span>
                            <span><strong><?php echo esc_html($f['name']); ?></strong></span>
                        </li>
                    <?php endforeach; ?>
                    <?php foreach ($premium_features as $pf): ?>
                        <li class="ccm-premium-highlight">
                            <span class="ccm-check ccm-check-premium">✓</span>
                            <span>
                                <strong><?php echo esc_html($pf['name']); ?></strong>
                                <small><?php echo esc_html($pf['description']); ?></small>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    <li class="ccm-premium-highlight">
                        <span class="ccm-check ccm-check-premium">✓</span>
                        <span><strong>Priority Support</strong></span>
                    </li>
                </ul>
                <div class="ccm-premium-plan-cta">
                    <a href="<?php echo esc_url($checkout_url); ?>" class="ccm-button ccm-button-premium" target="_blank" rel="noopener">Get Premium</a>
                </div>
            </div>
        </div>
    </div>
<?php }

/**
 * Render the premium dashboard card (appears on System Info page).
 */
function ccm_tools_render_premium_dashboard_card(): void {
    $is_premium   = ccm_tools_is_premium();
    $status       = ccm_tools_premium_get_status();
    $checkout_url = ccm_tools_premium_get_checkout_url();
    $account_url  = ccm_tools_premium_get_account_url();
    ?>
    <div class="ccm-card ccm-card-premium">
        <div class="ccm-card-header" style="display: flex; align-items: center; justify-content: space-between;">
            <h2><?php _e('CCM Tools Premium', 'ccm-tools'); ?></h2>
            <?php if ($is_premium): ?>
                <span class="ccm-premium-badge ccm-premium-badge-pro">Active</span>
            <?php endif; ?>
        </div>
        <div class="ccm-card-body">
            <?php if ($is_premium): ?>
                <div class="ccm-premium-status-active">
                    <p>Your premium subscription is <strong class="ccm-success">active</strong>.</p>
                    <?php if (!empty($status['plan']) && $status['plan'] !== 'developer'): ?>
                        <p>Plan: <strong><?php echo esc_html(ucfirst($status['plan'])); ?></strong></p>
                    <?php endif; ?>
                    <?php if (!empty($status['expires'])): ?>
                        <p>Renews: <strong><?php echo esc_html(wp_date('F j, Y', strtotime($status['expires']))); ?></strong></p>
                    <?php endif; ?>
                    <div class="ccm-premium-active-features">
                        <h4>Your Premium Features</h4>
                        <?php foreach (ccm_tools_premium_features() as $f): ?>
                            <div class="ccm-premium-active-feature">
                                <span><?php echo esc_html($f['icon']); ?></span>
                                <strong><?php echo esc_html($f['name']); ?></strong>
                                — <?php echo esc_html($f['description']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p style="margin-top: var(--ccm-space-md);">
                        <a href="<?php echo esc_url($account_url); ?>" class="ccm-button ccm-button-small" target="_blank" rel="noopener">Manage Subscription</a>
                        <button type="button" id="premium-refresh-btn" class="ccm-button ccm-button-small ccm-button-secondary" style="margin-left: 0.5rem;">Refresh Status</button>
                    </p>
                </div>
            <?php else: ?>
                <p class="ccm-text-muted" style="margin-bottom: var(--ccm-space-md);">Unlock the full power of your WordPress site with AI-driven optimisation and enterprise Redis caching.</p>
                <?php ccm_tools_render_premium_comparison(); ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ─── Premium Admin Page ─────────────────────────────────────────

/**
 * Render the dedicated Premium admin page.
 *
 * Shows API key connection, premium status, and comparison table.
 */
function ccm_tools_render_premium_page(): void {
    $settings    = function_exists('ccm_tools_ai_hub_get_settings') ? ccm_tools_ai_hub_get_settings() : [];
    $hasKey      = !empty($settings['api_key']);
    $is_premium  = ccm_tools_is_premium();
    $status      = ccm_tools_premium_get_status();
    $checkout_url = ccm_tools_premium_get_checkout_url();
    $account_url  = ccm_tools_premium_get_account_url();
    ?>
    <div class="wrap ccm-tools ccm-tools-premium">
        <?php
        if (function_exists('ccm_tools_render_header_nav')) {
            ccm_tools_render_header_nav('ccm-tools-premium');
        }
        ?>

        <div class="ccm-content">

            <!-- Hub Connection Card -->
            <div class="ccm-card">
                <div class="ccm-card-header" style="display: flex; align-items: center; justify-content: space-between;">
                    <h2 style="margin: 0;"><?php _e('Hub Connection', 'ccm-tools'); ?></h2>
                    <span id="ai-hub-status" class="ccm-badge <?php echo $hasKey ? 'ccm-badge-info' : 'ccm-badge-warning'; ?>"><?php echo $hasKey ? 'Connected' : 'Not Connected'; ?></span>
                </div>
                <div class="ccm-card-body">
                    <p class="ccm-text-muted"><?php _e('Connect your site to the CCM Tools Hub to enable Premium features. Enter the API key provided when you added this site to your Premium account.', 'ccm-tools'); ?></p>
                    <input type="hidden" id="ai-hub-url" value="<?php echo esc_attr($settings['hub_url'] ?? 'https://api.tools.clickclick.media'); ?>">
                    <div class="ccm-ai-connection-row">
                        <div class="ccm-form-field">
                            <label for="ai-hub-key"><?php _e('API Key', 'ccm-tools'); ?></label>
                            <input type="password" id="ai-hub-key" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" placeholder="ccm_xxxx..." class="ccm-input">
                        </div>
                        <button type="button" id="ai-hub-save-btn" class="ccm-button ccm-button-secondary"><?php _e('Save', 'ccm-tools'); ?></button>
                        <button type="button" id="ai-hub-test-btn" class="ccm-button ccm-button-secondary"><?php _e('Test Connection', 'ccm-tools'); ?></button>
                    </div>
                    <div id="ai-hub-test-result" style="margin-top: 0.5rem;"></div>
                    <p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm); font-size: 0.85rem;">
                        <?php _e('Lost your API key?', 'ccm-tools'); ?>
                        <a href="<?php echo esc_url(trailingslashit(CCM_TOOLS_PREMIUM_URL) . 'login'); ?>" target="_blank" rel="noopener"><?php _e('Log in to your Premium account', 'ccm-tools'); ?></a>
                        <?php _e('to retrieve it.', 'ccm-tools'); ?>
                    </p>
                </div>
            </div>

            <!-- Premium Status Card -->
            <div class="ccm-card ccm-card-premium">
                <div class="ccm-card-header" style="display: flex; align-items: center; justify-content: space-between;">
                    <h2 style="margin: 0;"><?php _e('Subscription Status', 'ccm-tools'); ?></h2>
                    <?php if ($is_premium): ?>
                        <span class="ccm-premium-badge ccm-premium-badge-pro">Active</span>
                    <?php else: ?>
                        <span class="ccm-premium-badge ccm-premium-badge-free">Free</span>
                    <?php endif; ?>
                </div>
                <div class="ccm-card-body">
                    <?php if ($is_premium): ?>
                        <div class="ccm-premium-status-active">
                            <p>Your premium subscription is <strong class="ccm-success">active</strong>.</p>
                            <?php if (!empty($status['plan']) && $status['plan'] !== 'developer'): ?>
                                <p>Plan: <strong><?php echo esc_html(ucfirst($status['plan'])); ?></strong></p>
                            <?php endif; ?>
                            <?php if (!empty($status['expires'])): ?>
                                <p>Renews: <strong><?php echo esc_html(wp_date('F j, Y', strtotime($status['expires']))); ?></strong></p>
                            <?php endif; ?>
                            <div class="ccm-premium-active-features" style="margin-top: var(--ccm-space-md);">
                                <h4><?php _e('Your Premium Features', 'ccm-tools'); ?></h4>
                                <?php foreach (ccm_tools_premium_features() as $f): ?>
                                    <div class="ccm-premium-active-feature">
                                        <span><?php echo esc_html($f['icon']); ?></span>
                                        <strong><?php echo esc_html($f['name']); ?></strong>
                                        — <?php echo esc_html($f['description']); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p style="margin-top: var(--ccm-space-md);">
                                <a href="<?php echo esc_url($account_url); ?>" class="ccm-button ccm-button-small" target="_blank" rel="noopener"><?php _e('Manage Subscription', 'ccm-tools'); ?></a>
                                <button type="button" id="premium-refresh-btn" class="ccm-button ccm-button-small ccm-button-secondary" style="margin-left: 0.5rem;"><?php _e('Refresh Status', 'ccm-tools'); ?></button>
                            </p>
                        </div>
                    <?php else: ?>
                        <p class="ccm-text-muted" style="margin-bottom: var(--ccm-space-md);"><?php _e('Unlock the full power of your WordPress site with AI-driven optimisation and enterprise Redis caching.', 'ccm-tools'); ?></p>
                        <?php ccm_tools_render_premium_comparison(); ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
    <?php
}

// ─── AJAX Handlers ──────────────────────────────────────────────

/**
 * AJAX: Refresh premium status (clears cache and re-checks).
 */
add_action('wp_ajax_ccm_tools_premium_refresh', 'ccm_tools_ajax_premium_refresh');
function ccm_tools_ajax_premium_refresh(): void {
    check_ajax_referer('ccm-tools-nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized.', 'ccm-tools'));
    }

    ccm_tools_premium_clear_cache();
    $is_premium = ccm_tools_is_premium();
    $status     = ccm_tools_premium_get_status();

    wp_send_json_success(array(
        'premium' => $is_premium,
        'status'  => $status,
        'message' => $is_premium
            ? __('Premium subscription is active.', 'ccm-tools')
            : __('No active premium subscription found.', 'ccm-tools'),
    ));
}
