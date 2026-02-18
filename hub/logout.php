<?php
/**
 * Logout
 * 
 * @package CCM_API_Hub
 * @since 1.0.0
 */

require_once __DIR__ . '/config/config.php';

logout();
redirect(APP_URL . '/login.php');
