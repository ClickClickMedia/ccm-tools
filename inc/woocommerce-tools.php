<?php
/* 51c6071fe9f39239 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/* ee582c0bb3645a53 */
function ccm_tools_is_woocommerce_active() {
    return class_exists('WooCommerce');
}

/* 9a74f6091edd16da */
function ccm_tools_get_woocommerce_version() {
    if (!ccm_tools_is_woocommerce_active()) {
        return false;
    }
    
    return defined('WC_VERSION') ? WC_VERSION : (function_exists('WC') ? WC()->version : '');
}

/* 007e7b50e76f3ec9 */
function ccm_tools_init_woocommerce() {
    if (!ccm_tools_is_woocommerce_active()) {
        return;
    }
    
    // Check if admin-only payment methods are enabled
    $admin_payment_enabled = get_option('ccm_woo_admin_payment_enabled', 'no');
    
    if ($admin_payment_enabled === 'yes') {
        add_filter('woocommerce_available_payment_gateways', 'ccm_tools_filter_payment_gateways_for_admin');
    }
}

/* 63dc5617ef98bdeb */
function ccm_tools_filter_payment_gateways_for_admin($gateways) {
    // Only apply this filter if the setting is enabled
    $admin_payment_enabled = get_option('ccm_woo_admin_payment_enabled', 'no');
    if ($admin_payment_enabled !== 'yes') {
        return $gateways;
    }
    
    // Check if user is admin
    if (!ccm_tools_user_is_admin()) {
        // Remove COD and BACS for non-admin users
        if (isset($gateways['cod'])) {
            unset($gateways['cod']);
        }
        if (isset($gateways['bacs'])) {
            unset($gateways['bacs']);
        }
    } else {
        // Rename COD and BACS titles for admins to indicate they are test gateways
        if (isset($gateways['cod'])) {
            $gateways['cod']->title = 'CCM Test Gateway (COD)';
        }
        if (isset($gateways['bacs'])) {
            $gateways['bacs']->title = 'CCM Test Gateway (BACS)';
        }
    }
    
    return $gateways;
}

/* eae63b9a4328e09a */
function ccm_tools_get_woocommerce_info() {
    if (!ccm_tools_is_woocommerce_active()) {
        return false;
    }
    
    global $wpdb;
    
    // Get order counts - Updated to work with both legacy and HPOS (High-Performance Order Storage)
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
        method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled') &&
        \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
        // HPOS is enabled - use the orders table
        $orders_table = $wpdb->prefix . 'wc_orders';
        $orders_count = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$orders_table} 
            WHERE status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-cancelled', 'wc-refunded', 'wc-failed')
        ");
    } else {
        // Legacy posts table approach
        $orders_count = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'shop_order' 
            AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-cancelled', 'wc-refunded', 'wc-failed')
        ");
    }
    
    // Fallback using WooCommerce functions if available
    if (empty($orders_count) && function_exists('wc_get_orders')) {
        $orders = wc_get_orders(array(
            'status' => array('completed', 'processing', 'on-hold', 'pending', 'cancelled', 'refunded', 'failed'),
            'return' => 'ids',
            'limit' => -1
        ));
        $orders_count = count($orders);
    }
    
    // Ensure we have a valid number
    $orders_count = $orders_count ? intval($orders_count) : 0;
    
    // Get product counts
    $products_count = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$wpdb->posts} 
        WHERE post_type = 'product' 
        AND post_status = 'publish'
    ");
    
    // Ensure we have a valid number
    $products_count = $products_count ? intval($products_count) : 0;
    
    // Get customer counts - More accurate method
    if (function_exists('wc_get_customer_count')) {
        $customers_count = wc_get_customer_count();
    } else {
        // Fallback method
        $customers_count = $wpdb->get_var("
            SELECT COUNT(DISTINCT meta_value) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_customer_user' 
            AND meta_value > 0
        ");
        
        // If still empty, try counting users with customer role
        if (empty($customers_count)) {
            $customers_count = count_users();
            $customers_count = isset($customers_count['avail_roles']['customer']) ? $customers_count['avail_roles']['customer'] : 0;
        }
    }
    
    // Ensure we have a valid number
    $customers_count = $customers_count ? intval($customers_count) : 0;
    
    // Get payment gateways
    $payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
    
    return array(
        'version' => ccm_tools_get_woocommerce_version(),
        'orders_count' => $orders_count,
        'products_count' => $products_count,
        'customers_count' => $customers_count,
        'payment_gateways' => array_keys($payment_gateways),
        'currency' => get_woocommerce_currency(),
        'currency_symbol' => get_woocommerce_currency_symbol(),
        'base_country' => WC()->countries->get_base_country(),
        'base_location' => WC()->countries->get_base_address(),
    );
}

/* 5ad594931aac81ee */
function ccm_tools_check_payment_gateways() {
    if (!ccm_tools_is_woocommerce_active()) {
        return false;
    }

    $all_gateways = WC()->payment_gateways->payment_gateways();

    return array(
        'cod_available' => isset($all_gateways['cod']),
        'bacs_available' => isset($all_gateways['bacs']),
        'cod_enabled' => isset($all_gateways['cod']) && $all_gateways['cod']->enabled === 'yes',
        'bacs_enabled' => isset($all_gateways['bacs']) && $all_gateways['bacs']->enabled === 'yes',
    );
}

// Initialize WooCommerce tools
add_action('init', 'ccm_tools_init_woocommerce');
