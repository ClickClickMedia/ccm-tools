<?php
/**
 * Root Index
 * 
 * Redirects to setup wizard (first run) or admin dashboard.
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

define('SETUP_MODE', true);
require_once __DIR__ . '/config/config.php';

if (!Settings::isLoaded() || Settings::get('setup_complete', '0') !== '1') {
    redirect('setup.php');
} elseif (isLoggedIn()) {
    redirect('admin/');
} else {
    redirect('login.php');
}
