<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validate that a table name exists in the current database
 * Security: Prevents SQL injection by validating against actual database tables
 * 
 * @param string $table_name The table name to validate
 * @return bool True if valid, false otherwise
 */
function ccm_tools_validate_table_name_optimize($table_name) {
    global $wpdb;
    
    if (empty($table_name) || !is_string($table_name)) {
        return false;
    }
    
    // Get all tables in the database
    $tables = $wpdb->get_col("SHOW TABLES");
    
    // Check if the provided table name exists in the database
    return in_array($table_name, $tables, true);
}

/**
 * Get appropriate collation based on MySQL/MariaDB version
 */
function ccm_tools_get_appropriate_collation_optimize($version_string) {
    // For MariaDB, use utf8mb4_unicode_520_ci as it's more widely supported
    if (stripos($version_string, 'mariadb') !== false) {
        // Extract MariaDB version number
        if (preg_match('/(\d+\.\d+\.\d+)/', $version_string, $matches)) {
            $version = $matches[1];
            // MariaDB 10.6+ supports utf8mb4_0900_ai_ci, but utf8mb4_unicode_520_ci is more reliable
            return 'utf8mb4_unicode_520_ci';
        }
        return 'utf8mb4_unicode_520_ci';
    }
    
    // For MySQL, check if it's 8.0+
    $is_mysql8_plus = version_compare($version_string, '8.0.0', '>=');
    return $is_mysql8_plus ? 'utf8mb4_0900_ai_ci' : 'utf8mb4_unicode_520_ci';
}

/**
 * Get tables that need optimization
 */
function ccm_tools_get_tables_to_optimize() {
    global $wpdb;
    
    try {
        $tables = $wpdb->get_results("SHOW TABLES", 'ARRAY_N');
        $table_names = [];
        
        if (!$tables) {
            return [
                'tables' => [],
                'total_count' => 0,
                'error' => 'Could not retrieve tables from database'
            ];
        }
        
        foreach ($tables as $table) {
            $table_names[] = $table[0];
        }
        
        return [
            'tables' => $table_names,
            'total_count' => count($table_names)
        ];
        
    } catch (Exception $e) {
        return [
            'tables' => [],
            'total_count' => 0,
            'error' => 'Exception: ' . $e->getMessage()
        ];
    }
}

/**
 * Remove all transients (standard and site) from the database and cache.
 */
function ccm_tools_clear_all_transients() {
    global $wpdb;

    $report = [
        'success' => true,
        'details' => [],
        'total_removed' => 0,
    ];

    $targets = [
        [
            'table' => $wpdb->options,
            'column' => 'option_name',
            'label' => 'options table',
            'patterns' => [
                '\\_transient\\_%' => 'transient entries',
                '\\_site_transient\\_%' => 'site transient entries',
            ],
        ],
    ];

    if (is_multisite() && !empty($wpdb->sitemeta)) {
        $targets[] = [
            'table' => $wpdb->sitemeta,
            'column' => 'meta_key',
            'label' => 'site meta table',
            'patterns' => [
                '\\_site_transient\\_%' => 'network transient entries',
            ],
        ];
    }

    foreach ($targets as $target) {
        foreach ($target['patterns'] as $pattern => $description) {
            $sql = "DELETE FROM {$target['table']} WHERE {$target['column']} LIKE %s ESCAPE '\\\\'";
            $deleted = $wpdb->query($wpdb->prepare($sql, $pattern));

            if ($deleted === false) {
                $report['success'] = false;
                $report['details'][] = sprintf('Failed to remove %s from %s', $description, $target['label']);
            } elseif ($deleted > 0) {
                $report['total_removed'] += $deleted;
                $report['details'][] = sprintf('%d %s removed from %s', $deleted, $description, $target['label']);
            }
        }
    }

    if ($report['total_removed'] > 0) {
        array_unshift($report['details'], sprintf('Total of %d transient entries removed', $report['total_removed']));
    } elseif (empty($report['details'])) {
        $report['details'][] = 'No transient entries found';
    }

    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        $report['details'][] = 'Object cache flushed to remove cached transients';
    }

    return $report;
}

/**
 * Optimize a single table
 */
function ccm_tools_optimize_single_table($table_name) {
    global $wpdb;
    
    // SECURITY: Validate table name exists in database to prevent SQL injection
    if (!ccm_tools_validate_table_name_optimize($table_name)) {
        return [
            'success' => false,
            'table_name' => sanitize_text_field($table_name),
            'message' => 'Invalid table name provided',
            'messages' => ['Invalid table name provided'],
            'collation_updated' => false,
            'original_collation' => 'unknown',
            'new_collation' => 'unknown'
        ];
    }
    
    try {
        // Check MySQL/MariaDB version and determine appropriate collation
        $mysql_version = $wpdb->get_var("SELECT VERSION()");
        $collation = ccm_tools_get_appropriate_collation_optimize($mysql_version);
        
        $results = [];
        
        // Optimize the table
        $optimize_result = $wpdb->query("OPTIMIZE TABLE `{$table_name}`");
        if ($optimize_result === false) {
            $results[] = 'Failed to optimize table';
        } else {
            $results[] = 'Table optimized successfully';
        }
        
        // Get database name for INFORMATION_SCHEMA queries
        $database_name = $wpdb->dbname;
        if (empty($database_name)) {
            $database_name = defined('DB_NAME') ? DB_NAME : '';
        }
        
        // Update table collation if necessary - get current collation using INFORMATION_SCHEMA
        $table_status = null;
        if (!empty($database_name)) {
            $table_status = $wpdb->get_row(
                $wpdb->prepare("SELECT TABLE_COLLATION as Collation FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", $database_name, $table_name),
                'OBJECT'
            );
        }
        
        $collation_updated = false;
        
        if ($table_status && $table_status->Collation !== $collation) {
            $collation_result = $wpdb->query("ALTER TABLE `{$table_name}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}");
            if ($collation_result === false) {
                $results[] = 'Failed to update collation to ' . $collation;
            } else {
                $results[] = 'Collation updated to ' . $collation;
                $collation_updated = true;
            }
        }
        
        return [
            'success' => true,
            'table_name' => $table_name,
            'messages' => $results,
            'collation_updated' => $collation_updated,
            'original_collation' => $table_status ? $table_status->Collation : 'unknown',
            'new_collation' => $collation_updated ? $collation : ($table_status ? $table_status->Collation : 'unknown')
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'table_name' => $table_name,
            'message' => 'Exception: ' . $e->getMessage(),
            'messages' => ['Exception: ' . $e->getMessage()],
            'collation_updated' => false,
            'original_collation' => 'unknown',
            'new_collation' => 'unknown'
        ];
    }
}

/**
 * Handle initial optimization setup (indexes and transients)
 */
function ccm_tools_optimize_initial_setup() {
    global $wpdb;
    $results = [];
    
    try {
        // Check and update index on postmeta
        $postmeta_index = $wpdb->get_results("SHOW INDEX FROM {$wpdb->postmeta} WHERE KEY_NAME = 'meta_key' OR KEY_NAME = 'ccm_index'");

        if (!empty($postmeta_index)) {
            // Check if 'meta_key' index exists and remove it
            foreach ($postmeta_index as $index) {
                if ($index->Key_name === 'meta_key') {
                    $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `meta_key`");
                    $results[] = 'Existing \'meta_key\' index removed from ' . $wpdb->postmeta;
                }
            }
        }

        // Check and update index on postmeta
        $postmeta_indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->postmeta} WHERE Column_name = 'meta_key'");
        $ccm_index_exists = false;
        $ccm_index_correct_size = false;
        $other_indexes_exist = false;

        foreach ($postmeta_indexes as $index) {
            if ($index->Key_name === 'ccm_index') {
                $ccm_index_exists = true;
                if ($index->Sub_part == 200) {
                    $ccm_index_correct_size = true;
                }
            } else {
                $other_indexes_exist = true;
            }
        }

        // Drop any existing index on meta_key that isn't ccm_index
        if ($other_indexes_exist) {
            foreach ($postmeta_indexes as $index) {
                if ($index->Key_name !== 'ccm_index') {
                    $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `{$index->Key_name}`");
                    $results[] = 'Existing index \'' . $index->Key_name . '\' removed from ' . $wpdb->postmeta;
                }
            }
        }

        // Add or update 'ccm_index' if it doesn't exist or has incorrect size
        if (!$ccm_index_exists || !$ccm_index_correct_size) {
            if ($ccm_index_exists) {
                $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `ccm_index`");
            }
            $wpdb->query("ALTER TABLE {$wpdb->postmeta} ADD INDEX `ccm_index` (`meta_key`(200))");
            $results[] = 'Index \'ccm_index\' added or updated on ' . $wpdb->postmeta . ' with size 200';
        } else {
            $results[] = 'Index \'ccm_index\' already exists on ' . $wpdb->postmeta . ' with correct size of 200';
        }

        // Delete transients
        $transient_report = ccm_tools_clear_all_transients();
        foreach ($transient_report['details'] as $detail) {
            $results[] = $detail;
        }
        if (!$transient_report['success']) {
            $results[] = 'Warning: Some transient entries could not be removed';
        }
        
        return [
            'success' => true,
            'messages' => $results
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Exception during initial setup: ' . $e->getMessage()
        ];
    }
}

function ccm_tools_optimize_database() {
    global $wpdb;
    $result = '';

    // Check MySQL/MariaDB version and determine appropriate collation
    $mysql_version = $wpdb->get_var("SELECT VERSION()");
    $collation = ccm_tools_get_appropriate_collation_optimize($mysql_version);

    // Check and update index on postmeta
    $postmeta_index = $wpdb->get_results("SHOW INDEX FROM {$wpdb->postmeta} WHERE KEY_NAME = 'meta_key' OR KEY_NAME = 'ccm_index'");

    if (!empty($postmeta_index)) {
        // Check if 'meta_key' index exists and remove it
        foreach ($postmeta_index as $index) {
            if ($index->Key_name === 'meta_key') {
                $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `meta_key`");
                $result .= '<p><span class="ccm-icon ccm-info">i</span>Existing \'meta_key\' index removed from ' . $wpdb->postmeta . '</p>';
            }
        }
    }

    // Check and update index on postmeta
    $postmeta_indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->postmeta} WHERE Column_name = 'meta_key'");
    $ccm_index_exists = false;
    $ccm_index_correct_size = false;
    $other_indexes_exist = false;

    foreach ($postmeta_indexes as $index) {
        if ($index->Key_name === 'ccm_index') {
            $ccm_index_exists = true;
            if ($index->Sub_part == 200) {
                $ccm_index_correct_size = true;
            }
        } else {
            $other_indexes_exist = true;
        }
    }

    // Drop any existing index on meta_key that isn't ccm_index
    if ($other_indexes_exist) {
        foreach ($postmeta_indexes as $index) {
            if ($index->Key_name !== 'ccm_index') {
                $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `{$index->Key_name}`");
                $result .= '<p><span class="ccm-icon ccm-info">i</span>Existing index \'' . $index->Key_name . '\' removed from ' . $wpdb->postmeta . '</p>';
            }
        }
    }

    // Add or update 'ccm_index' if it doesn't exist or has incorrect size
    if (!$ccm_index_exists || !$ccm_index_correct_size) {
        if ($ccm_index_exists) {
            $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `ccm_index`");
        }
        $wpdb->query("ALTER TABLE {$wpdb->postmeta} ADD INDEX `ccm_index` (`meta_key`(200))");
        $result .= '<p><span class="ccm-icon ccm-success">✓</span>Index \'ccm_index\' added or updated on ' . $wpdb->postmeta . ' with size 200</p>';
    } else {
        $result .= '<p><span class="ccm-icon ccm-info">i</span>Index \'ccm_index\' already exists on ' . $wpdb->postmeta . ' with correct size of 200</p>';
    }

    // Delete transients
    $transient_report = ccm_tools_clear_all_transients();
    foreach ($transient_report['details'] as $detail) {
        $icon_class = stripos($detail, 'Failed') !== false || stripos($detail, 'Warning') !== false ? 'ccm-warning' : 'ccm-info';
        $result .= '<p><span class="ccm-icon ' . $icon_class . '">i</span>' . $detail . '</p>';
    }
    if (!$transient_report['success']) {
        $result .= '<p><span class="ccm-icon ccm-warning">!</span>Some transient entries could not be removed</p>';
    }

    // Optimize tables
    $tables = $wpdb->get_results("SHOW TABLES", 'ARRAY_N');
    
    // Get database name for INFORMATION_SCHEMA queries
    $database_name = $wpdb->dbname;
    if (empty($database_name)) {
        $database_name = defined('DB_NAME') ? DB_NAME : '';
    }
    
    foreach ($tables as $table) {
        $table_name = $table[0];
        $wpdb->query("OPTIMIZE TABLE `{$table_name}`");
        $result .= '<p><span class="ccm-icon ccm-success">✓</span>' . $table_name . ' optimized</p>';

        // Update table collation if necessary - use INFORMATION_SCHEMA for MariaDB compatibility
        if (!empty($database_name)) {
            $table_status = $wpdb->get_row(
                $wpdb->prepare("SELECT TABLE_COLLATION as Collation FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", $database_name, $table_name),
                'OBJECT'
            );
            
            if ($table_status && $table_status->Collation !== $collation) {
                $wpdb->query("ALTER TABLE `{$table_name}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}");
                $result .= '<p><span class="ccm-icon ccm-success">✓</span>' . $table_name . ' collation updated to ' . $collation . '</p>';
            }
        }
    }

    return $result;
}