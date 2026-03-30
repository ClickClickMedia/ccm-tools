<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database Optimization Functions for CCM Tools
 * 
 * @package CCM Tools
 * @since 7.1.0
 */

/**
 * Get safe index prefix length for meta_key columns
 * MySQL/MariaDB with utf8mb4 has max key length constraints
 * 191 chars is safe for all utf8mb4 configurations
 */
function ccm_tools_get_safe_index_length() {
    return 191; // Safe for utf8mb4 (191 * 4 = 764 bytes, under 767 byte limit)
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
 * Get appropriate collation for WordPress databases.
 *
 * Always returns utf8mb4_unicode_520_ci to match WordPress core default.
 * Using utf8mb4_0900_ai_ci on MySQL 8.0+ would cause "Illegal mix of collations"
 * errors when JOINing with tables created by WordPress/plugins using 520_ci.
 *
 * @param string $version_string MySQL/MariaDB version (accepted for backward compat, ignored)
 * @return string Always 'utf8mb4_unicode_520_ci'
 */
function ccm_tools_get_appropriate_collation_optimize($version_string = '') {
    return 'utf8mb4_unicode_520_ci';
}

/**
 * Get available optimization options with their default states
 */
function ccm_tools_get_optimization_options() {
    return array(
        // Safe options - checked by default
        'clear_transients' => array(
            'label' => __('Clear all transients', 'ccm-tools'),
            'description' => __('Remove all temporary cached data from the database (including active transients)', 'ccm-tools'),
            'default' => true,
            'risk' => 'safe'
        ),
        'optimize_tables' => array(
            'label' => __('Optimize database tables', 'ccm-tools'),
            'description' => __('Defragment tables and reclaim unused space', 'ccm-tools'),
            'default' => true,
            'risk' => 'safe'
        ),
        'convert_innodb' => array(
            'label' => __('Convert tables to InnoDB', 'ccm-tools'),
            'description' => __('Convert non-InnoDB tables to the InnoDB storage engine', 'ccm-tools'),
            'default' => true,
            'risk' => 'safe'
        ),
        'update_collation' => array(
            'label' => __('Update table collations', 'ccm-tools'),
            'description' => __('Convert tables to modern utf8mb4 collation', 'ccm-tools'),
            'default' => true,
            'risk' => 'safe'
        ),
        'clean_spam_comments' => array(
            'label' => __('Delete spam comments', 'ccm-tools'),
            'description' => __('Remove all comments marked as spam', 'ccm-tools'),
            'default' => true,
            'risk' => 'safe'
        ),
        'clean_trashed_comments' => array(
            'label' => __('Delete trashed comments', 'ccm-tools'),
            'description' => __('Remove comments in trash older than 30 days', 'ccm-tools'),
            'default' => true,
            'risk' => 'safe'
        ),
        'clean_trashed_posts' => array(
            'label' => __('Delete trashed posts', 'ccm-tools'),
            'description' => __('Remove posts in trash older than 30 days', 'ccm-tools'),
            'default' => true,
            'risk' => 'safe'
        ),
        'clean_auto_drafts' => array(
            'label' => __('Delete old auto-drafts', 'ccm-tools'),
            'description' => __('Remove auto-save drafts older than 7 days', 'ccm-tools'),
            'default' => true,
            'risk' => 'safe'
        ),
        
        // Moderate risk - unchecked by default
        'add_postmeta_index' => array(
            'label' => __('Add postmeta index', 'ccm-tools'),
            'description' => __('Add optimized index on wp_postmeta.meta_key (191 chars)', 'ccm-tools'),
            'default' => false,
            'risk' => 'moderate'
        ),
        'add_postmeta_composite_index' => array(
            'label' => __('Add postmeta composite index', 'ccm-tools'),
            'description' => __('Speed up custom field and metadata lookups with a high-performance covering index — especially effective on sites using ACF or WooCommerce', 'ccm-tools'),
            'default' => false,
            'risk' => 'moderate',
            'premium' => true
        ),
        'add_usermeta_index' => array(
            'label' => __('Add usermeta index', 'ccm-tools'),
            'description' => __('Add optimized index on wp_usermeta.meta_key', 'ccm-tools'),
            'default' => false,
            'risk' => 'moderate'
        ),
        'add_commentmeta_index' => array(
            'label' => __('Add commentmeta index', 'ccm-tools'),
            'description' => __('Add optimized index on wp_commentmeta.meta_key', 'ccm-tools'),
            'default' => false,
            'risk' => 'moderate'
        ),
        'add_termmeta_index' => array(
            'label' => __('Add termmeta index', 'ccm-tools'),
            'description' => __('Add optimized index on wp_termmeta.meta_key', 'ccm-tools'),
            'default' => false,
            'risk' => 'moderate'
        ),
        'clean_orphaned_postmeta' => array(
            'label' => __('Delete orphaned postmeta', 'ccm-tools'),
            'description' => __('Remove postmeta entries for deleted posts', 'ccm-tools'),
            'default' => false,
            'risk' => 'moderate'
        ),
        'clean_orphaned_commentmeta' => array(
            'label' => __('Delete orphaned commentmeta', 'ccm-tools'),
            'description' => __('Remove commentmeta entries for deleted comments', 'ccm-tools'),
            'default' => false,
            'risk' => 'moderate'
        ),
        'clean_oembed_cache' => array(
            'label' => __('Clear oEmbed cache', 'ccm-tools'),
            'description' => __('Remove cached embed data from postmeta', 'ccm-tools'),
            'default' => false,
            'risk' => 'moderate'
        ),
        'limit_revisions' => array(
            'label' => __('Limit post revisions (keep 5)', 'ccm-tools'),
            'description' => __('Delete excess revisions, keeping the 5 most recent per post', 'ccm-tools'),
            'default' => false,
            'risk' => 'moderate'
        ),
        
        // Higher risk - unchecked, with warning
        'delete_all_revisions' => array(
            'label' => __('Delete ALL post revisions', 'ccm-tools'),
            'description' => __('⚠️ Permanently removes all post revisions - cannot be undone', 'ccm-tools'),
            'default' => false,
            'risk' => 'high'
        ),
        'clean_orphaned_termmeta' => array(
            'label' => __('Delete orphaned termmeta', 'ccm-tools'),
            'description' => __('⚠️ Remove termmeta for deleted terms - verify backups first', 'ccm-tools'),
            'default' => false,
            'risk' => 'high'
        ),
        'clean_orphaned_relationships' => array(
            'label' => __('Delete orphaned term relationships', 'ccm-tools'),
            'description' => __('⚠️ Remove term relationships for deleted posts', 'ccm-tools'),
            'default' => false,
            'risk' => 'high'
        ),
    );
}
function ccm_tools_get_tables_to_optimize($do_optimize = true, $do_collation = false, $do_engine = false) {
    global $wpdb;
    
    try {
        $database_name = $wpdb->dbname;
        if (empty($database_name)) {
            $database_name = defined('DB_NAME') ? DB_NAME : '';
        }
        
        if (empty($database_name)) {
            return [
                'tables' => [],
                'total_count' => 0,
                'error' => 'Unable to determine database name'
            ];
        }
        
        $collation = ccm_tools_get_appropriate_collation_optimize();
        
        // Build conditions for tables that need at least one operation
        $conditions = [];
        if ($do_optimize) {
            $conditions[] = ccm_tools_optimize_fragmentation_condition();
        }
        if ($do_collation) {
            $conditions[] = $wpdb->prepare('TABLE_COLLATION != %s', $collation);
        }
        if ($do_engine) {
            $conditions[] = "ENGINE != 'InnoDB'";
        }
        
        if (empty($conditions)) {
            // Fallback: return all tables
            $conditions[] = '1=1';
        }
        
        $where = implode(' OR ', $conditions);
        $sql = $wpdb->prepare(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = %s AND ({$where})",
            $database_name
        );
        
        $tables = $wpdb->get_col($sql);
        
        if (!$tables) {
            $tables = [];
        }
        
        return [
            'tables' => $tables,
            'total_count' => count($tables)
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
            if ($index->Key_name === 'ccm_meta_key' || $index->Key_name === 'ccm_index') {
                $ccm_index_exists = true;
                if ($index->Sub_part == 191) {
                    $ccm_index_correct_size = true;
                }
            } else {
                $other_indexes_exist = true;
            }
        }

        // Drop any existing index on meta_key that isn't ccm_meta_key
        if ($other_indexes_exist) {
            foreach ($postmeta_indexes as $index) {
                if ($index->Key_name !== 'ccm_meta_key' && $index->Key_name !== 'ccm_index') {
                    $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `{$index->Key_name}`");
                    $results[] = 'Existing index \'' . $index->Key_name . '\' removed from ' . $wpdb->postmeta;
                }
            }
        }

        // Migrate legacy ccm_index to ccm_meta_key with correct size
        if ($ccm_index_exists) {
            // Drop any legacy ccm_index
            $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `ccm_index`");
        }

        // Add or update 'ccm_meta_key' if it doesn't exist or has incorrect size
        if (!$ccm_index_exists || !$ccm_index_correct_size) {
            $wpdb->query("ALTER TABLE {$wpdb->postmeta} ADD INDEX `ccm_meta_key` (`meta_key`(191))");
            $results[] = 'Index \'ccm_meta_key\' added on ' . $wpdb->postmeta . ' with size 191';
        } else {
            $results[] = 'Index \'ccm_meta_key\' already exists on ' . $wpdb->postmeta . ' with correct size of 191';
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
        if ($index->Key_name === 'ccm_meta_key' || $index->Key_name === 'ccm_index') {
            $ccm_index_exists = true;
            if ($index->Sub_part == 191) {
                $ccm_index_correct_size = true;
            }
        } else {
            $other_indexes_exist = true;
        }
    }

    // Drop any existing index on meta_key that isn't ccm_meta_key
    if ($other_indexes_exist) {
        foreach ($postmeta_indexes as $index) {
            if ($index->Key_name !== 'ccm_meta_key' && $index->Key_name !== 'ccm_index') {
                $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `{$index->Key_name}`");
                $result .= '<p><span class="ccm-icon ccm-info">i</span>Existing index \'' . $index->Key_name . '\' removed from ' . $wpdb->postmeta . '</p>';
            }
        }
    }

    // Migrate legacy ccm_index to ccm_meta_key with correct size
    if ($ccm_index_exists) {
        $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `ccm_index`");
    }

    // Add or update 'ccm_meta_key' if it doesn't exist or has incorrect size
    if (!$ccm_index_exists || !$ccm_index_correct_size) {
        $wpdb->query("ALTER TABLE {$wpdb->postmeta} ADD INDEX `ccm_meta_key` (`meta_key`(191))");
        $result .= '<p><span class="ccm-icon ccm-success">✓</span>Index \'ccm_meta_key\' added on ' . $wpdb->postmeta . ' with size 191</p>';
    } else {
        $result .= '<p><span class="ccm-icon ccm-info">i</span>Index \'ccm_meta_key\' already exists on ' . $wpdb->postmeta . ' with correct size of 191</p>';
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

/**
 * =====================================================
 * NEW CLEANUP FUNCTIONS FOR SELECTIVE OPTIMIZATION
 * =====================================================
 */

/**
 * Run selected optimization tasks
 * 
 * @param array $selected_options Array of option keys to run
 * @return array Results of each operation
 */
function ccm_tools_run_selected_optimizations($selected_options) {
    $results = array();
    $available_options = ccm_tools_get_optimization_options();
    
    foreach ($selected_options as $option) {
        if (!isset($available_options[$option])) {
            continue;
        }
        
        $function_name = 'ccm_tools_optimization_' . $option;
        if (function_exists($function_name)) {
            $results[$option] = call_user_func($function_name);
        }
    }
    
    return $results;
}

/**
 * Clear expired transients
 */
function ccm_tools_optimization_clear_transients() {
    $report = ccm_tools_clear_all_transients();
    return array(
        'success' => $report['success'],
        'message' => implode('; ', $report['details']),
        'count' => $report['total_removed']
    );
}

/**
 * SQL condition for tables with meaningful fragmentation.
 * InnoDB retains some Data_free after OPTIMIZE TABLE, so we use a ratio:
 * overhead must be >20% of table size AND >5MB absolute.
 */
function ccm_tools_optimize_fragmentation_condition() {
    return '(Data_free > 5242880 AND Data_free > 0.2 * (Data_length + Index_length))';
}

/**
 * Optimize database tables that have significant fragmentation.
 * Uses ratio-based detection and saves a cooldown timestamp.
 */
function ccm_tools_optimization_optimize_tables() {
    global $wpdb;
    
    $database_name = $wpdb->dbname;
    if (empty($database_name)) {
        $database_name = defined('DB_NAME') ? DB_NAME : '';
    }
    
    $frag_condition = ccm_tools_optimize_fragmentation_condition();
    $tables = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = %s AND {$frag_condition}",
            $database_name
        )
    );
    
    if (empty($tables)) {
        update_option('ccm_tools_last_db_optimize', time());
        return array(
            'success' => true,
            'message' => __('All tables are already optimized', 'ccm-tools'),
            'count' => 0
        );
    }
    
    $optimized = 0;
    $failed = 0;
    
    foreach ($tables as $table_name) {
        if (!ccm_tools_validate_table_name_optimize($table_name)) {
            continue;
        }
        $result = $wpdb->query("OPTIMIZE TABLE `{$table_name}`");
        if ($result !== false) {
            $optimized++;
        } else {
            $failed++;
        }
    }
    
    update_option('ccm_tools_last_db_optimize', time());
    
    return array(
        'success' => $failed === 0,
        'message' => sprintf(__('%d tables optimized', 'ccm-tools'), $optimized) . ($failed > 0 ? sprintf(__(', %d failed', 'ccm-tools'), $failed) : ''),
        'count' => $optimized
    );
}

/**
 * Convert non-InnoDB tables to InnoDB engine
 */
function ccm_tools_optimization_convert_innodb() {
    global $wpdb;
    
    $database_name = $wpdb->dbname;
    if (empty($database_name)) {
        $database_name = defined('DB_NAME') ? DB_NAME : '';
    }
    
    $tables = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = %s AND ENGINE != 'InnoDB'",
            $database_name
        )
    );
    
    if (empty($tables)) {
        return array(
            'success' => true,
            'message' => __('All tables already use InnoDB', 'ccm-tools'),
            'count' => 0
        );
    }
    
    $converted = 0;
    $failed = 0;
    
    foreach ($tables as $table_name) {
        if (!ccm_tools_validate_table_name_optimize($table_name)) {
            continue;
        }
        $result = $wpdb->query("ALTER TABLE `{$table_name}` ENGINE = InnoDB");
        if ($result !== false) {
            $converted++;
        } else {
            $failed++;
        }
    }
    
    return array(
        'success' => $failed === 0,
        'message' => sprintf(__('%d tables converted to InnoDB', 'ccm-tools'), $converted) . ($failed > 0 ? sprintf(__(', %d failed', 'ccm-tools'), $failed) : ''),
        'count' => $converted
    );
}

/**
 * Update table collations to utf8mb4
 */
function ccm_tools_optimization_update_collation() {
    global $wpdb;
    
    $mysql_version = $wpdb->get_var("SELECT VERSION()");
    $collation = ccm_tools_get_appropriate_collation_optimize($mysql_version);
    
    $database_name = $wpdb->dbname;
    if (empty($database_name)) {
        $database_name = defined('DB_NAME') ? DB_NAME : '';
    }
    
    $tables = $wpdb->get_results("SHOW TABLES", 'ARRAY_N');
    $updated = 0;
    $skipped = 0;
    
    foreach ($tables as $table) {
        $table_name = $table[0];
        
        $table_status = $wpdb->get_row(
            $wpdb->prepare("SELECT TABLE_COLLATION as Collation FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", $database_name, $table_name),
            'OBJECT'
        );
        
        if ($table_status && $table_status->Collation !== $collation) {
            $result = $wpdb->query("ALTER TABLE `{$table_name}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}");
            if ($result !== false) {
                $updated++;
            }
        } else {
            $skipped++;
        }
    }
    
    return array(
        'success' => true,
        'message' => sprintf(__('%d tables updated to %s, %d already correct', 'ccm-tools'), $updated, $collation, $skipped),
        'count' => $updated
    );
}

/**
 * Delete spam comments
 */
function ccm_tools_optimization_clean_spam_comments() {
    global $wpdb;
    
    $count = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
    
    // Clean orphaned commentmeta for deleted comments
    if ($count > 0) {
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
    }
    
    return array(
        'success' => $count !== false,
        'message' => sprintf(__('%d spam comments deleted', 'ccm-tools'), $count ?: 0),
        'count' => $count ?: 0
    );
}

/**
 * Delete trashed comments older than 30 days
 */
function ccm_tools_optimization_clean_trashed_comments() {
    global $wpdb;
    
    $count = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash' AND comment_date < %s",
            gmdate('Y-m-d H:i:s', strtotime('-30 days'))
        )
    );
    
    // Clean orphaned commentmeta
    if ($count > 0) {
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
    }
    
    return array(
        'success' => $count !== false,
        'message' => sprintf(__('%d trashed comments deleted (>30 days old)', 'ccm-tools'), $count ?: 0),
        'count' => $count ?: 0
    );
}

/**
 * Delete trashed posts older than 30 days
 */
function ccm_tools_optimization_clean_trashed_posts() {
    global $wpdb;
    
    // Get IDs of posts to delete for cleanup
    $post_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified < %s",
            gmdate('Y-m-d H:i:s', strtotime('-30 days'))
        )
    );
    
    $count = count($post_ids);
    
    if ($count > 0) {
        $ids_placeholder = implode(',', array_map('intval', $post_ids));
        
        // Delete the posts
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$ids_placeholder})");
        
        // Clean up postmeta
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_placeholder})");
        
        // Clean up term relationships
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$ids_placeholder})");
        
        // Clean up comments
        $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ({$ids_placeholder})");
        
        // Update term counts after removing posts
        wp_defer_term_counting(false);
    }
    
    return array(
        'success' => true,
        'message' => sprintf(__('%d trashed posts deleted (>30 days old)', 'ccm-tools'), $count),
        'count' => $count
    );
}

/**
 * Delete auto-drafts older than 7 days
 */
function ccm_tools_optimization_clean_auto_drafts() {
    global $wpdb;
    
    $post_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified < %s",
            gmdate('Y-m-d H:i:s', strtotime('-7 days'))
        )
    );
    
    $count = count($post_ids);
    
    if ($count > 0) {
        $ids_placeholder = implode(',', array_map('intval', $post_ids));
        
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$ids_placeholder})");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_placeholder})");
    }
    
    return array(
        'success' => true,
        'message' => sprintf(__('%d auto-drafts deleted (>7 days old)', 'ccm-tools'), $count),
        'count' => $count
    );
}

/**
 * Add optimized index to postmeta table
 */
function ccm_tools_optimization_add_postmeta_index() {
    global $wpdb;
    
    $index_length = ccm_tools_get_safe_index_length();
    $index_name = 'ccm_meta_key';
    
    return ccm_tools_add_meta_index($wpdb->postmeta, 'meta_key', $index_name, $index_length);
}

/**
 * Add optimized index to usermeta table
 */
function ccm_tools_optimization_add_usermeta_index() {
    global $wpdb;
    
    $index_length = ccm_tools_get_safe_index_length();
    $index_name = 'ccm_meta_key';
    
    return ccm_tools_add_meta_index($wpdb->usermeta, 'meta_key', $index_name, $index_length);
}

/**
 * Add optimized index to commentmeta table
 */
function ccm_tools_optimization_add_commentmeta_index() {
    global $wpdb;
    
    $index_length = ccm_tools_get_safe_index_length();
    $index_name = 'ccm_meta_key';
    
    return ccm_tools_add_meta_index($wpdb->commentmeta, 'meta_key', $index_name, $index_length);
}

/**
 * Add optimized index to termmeta table
 */
function ccm_tools_optimization_add_termmeta_index() {
    global $wpdb;
    
    $index_length = ccm_tools_get_safe_index_length();
    $index_name = 'ccm_meta_key';
    
    return ccm_tools_add_meta_index($wpdb->termmeta, 'meta_key', $index_name, $index_length);
}

/**
 * Add composite index to postmeta table (meta_key, meta_value, post_id)
 * Premium feature — massive performance improvement for meta queries, especially WooCommerce
 */
function ccm_tools_optimization_add_postmeta_composite_index() {
    global $wpdb;

    $index_name = 'idx_meta_key_value_postid';
    $table = $wpdb->postmeta;

    // Check if table exists
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$table_exists) {
        return array(
            'success' => false,
            'message' => sprintf(__('Table %s does not exist', 'ccm-tools'), $table),
            'count' => 0
        );
    }

    // Check if index already exists
    $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index_name}'");
    if (!empty($indexes)) {
        return array(
            'success' => true,
            'message' => sprintf(__('Composite index already exists on %s', 'ccm-tools'), $table),
            'count' => 0
        );
    }

    // Add composite index
    $result = $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`meta_key`, `meta_value`(191), `post_id`)");

    return array(
        'success' => $result !== false,
        'message' => $result !== false
            ? sprintf(__('Composite index added to %s (meta_key, meta_value, post_id)', 'ccm-tools'), $table)
            : sprintf(__('Failed to add composite index to %s', 'ccm-tools'), $table),
        'count' => $result !== false ? 1 : 0
    );
}

/**
 * Helper function to add an index to a meta table
 */
function ccm_tools_add_meta_index($table, $column, $index_name, $index_length) {
    global $wpdb;
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
    if (!$table_exists) {
        return array(
            'success' => false,
            'message' => sprintf(__('Table %s does not exist', 'ccm-tools'), $table),
            'count' => 0
        );
    }
    
    // Check existing indexes
    $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Column_name = '{$column}'");
    
    $ccm_index_exists = false;
    $ccm_index_correct = false;
    
    foreach ($indexes as $index) {
        if ($index->Key_name === $index_name) {
            $ccm_index_exists = true;
            if ($index->Sub_part == $index_length) {
                $ccm_index_correct = true;
            }
        }
    }
    
    if ($ccm_index_correct) {
        return array(
            'success' => true,
            'message' => sprintf(__('Index already exists on %s with correct size (%d)', 'ccm-tools'), $table, $index_length),
            'count' => 0
        );
    }
    
    // Drop old ccm index if exists with wrong size
    if ($ccm_index_exists) {
        $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `{$index_name}`");
    }
    
    // Drop any other meta_key indexes (except PRIMARY)
    foreach ($indexes as $index) {
        if ($index->Key_name !== $index_name && $index->Key_name !== 'PRIMARY') {
            $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `{$index->Key_name}`");
        }
    }
    
    // Add new index
    $result = $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`{$column}`({$index_length}))");
    
    return array(
        'success' => $result !== false,
        'message' => $result !== false 
            ? sprintf(__('Index added to %s (%d chars)', 'ccm-tools'), $table, $index_length)
            : sprintf(__('Failed to add index to %s', 'ccm-tools'), $table),
        'count' => $result !== false ? 1 : 0
    );
}

/**
 * Delete orphaned postmeta
 */
function ccm_tools_optimization_clean_orphaned_postmeta() {
    global $wpdb;
    
    $count = $wpdb->query(
        "DELETE pm FROM {$wpdb->postmeta} pm 
        LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
        WHERE p.ID IS NULL"
    );
    
    return array(
        'success' => $count !== false,
        'message' => sprintf(__('%d orphaned postmeta entries deleted', 'ccm-tools'), $count ?: 0),
        'count' => $count ?: 0
    );
}

/**
 * Delete orphaned commentmeta
 */
function ccm_tools_optimization_clean_orphaned_commentmeta() {
    global $wpdb;
    
    $count = $wpdb->query(
        "DELETE cm FROM {$wpdb->commentmeta} cm 
        LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID 
        WHERE c.comment_ID IS NULL"
    );
    
    return array(
        'success' => $count !== false,
        'message' => sprintf(__('%d orphaned commentmeta entries deleted', 'ccm-tools'), $count ?: 0),
        'count' => $count ?: 0
    );
}

/**
 * Clear oEmbed cache from postmeta
 */
function ccm_tools_optimization_clean_oembed_cache() {
    global $wpdb;
    
    $count = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            '%' . $wpdb->esc_like('_oembed_') . '%'
        )
    );
    
    return array(
        'success' => $count !== false,
        'message' => sprintf(__('%d oEmbed cache entries cleared', 'ccm-tools'), $count ?: 0),
        'count' => $count ?: 0
    );
}

/**
 * Limit post revisions to 5 per post
 */
function ccm_tools_optimization_limit_revisions() {
    global $wpdb;
    
    $keep_count = 5;
    $deleted = 0;
    
    // Get all parent posts that have revisions
    $parents = $wpdb->get_col(
        "SELECT DISTINCT post_parent FROM {$wpdb->posts} 
        WHERE post_type = 'revision' AND post_parent > 0"
    );
    
    foreach ($parents as $parent_id) {
        // Get revisions for this post, ordered by date (newest first)
        $revisions = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'revision' AND post_parent = %d 
                ORDER BY post_modified DESC",
                $parent_id
            )
        );
        
        // Skip if we have 5 or fewer
        if (count($revisions) <= $keep_count) {
            continue;
        }
        
        // Get IDs to delete (everything after the first 5)
        $to_delete = array_slice($revisions, $keep_count);
        
        if (!empty($to_delete)) {
            $ids_placeholder = implode(',', array_map('intval', $to_delete));
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$ids_placeholder})");
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_placeholder})");
            $deleted += count($to_delete);
        }
    }
    
    return array(
        'success' => true,
        'message' => sprintf(__('%d excess revisions deleted (kept %d per post)', 'ccm-tools'), $deleted, $keep_count),
        'count' => $deleted
    );
}

/**
 * Delete ALL post revisions
 */
function ccm_tools_optimization_delete_all_revisions() {
    global $wpdb;
    
    // Get all revision IDs
    $revision_ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'"
    );
    
    $count = count($revision_ids);
    
    if ($count > 0) {
        $ids_placeholder = implode(',', array_map('intval', $revision_ids));
        
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$ids_placeholder})");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_placeholder})");
    }
    
    return array(
        'success' => true,
        'message' => sprintf(__('%d revisions permanently deleted', 'ccm-tools'), $count),
        'count' => $count
    );
}

/**
 * Delete orphaned termmeta
 */
function ccm_tools_optimization_clean_orphaned_termmeta() {
    global $wpdb;
    
    $count = $wpdb->query(
        "DELETE tm FROM {$wpdb->termmeta} tm 
        LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id 
        WHERE t.term_id IS NULL"
    );
    
    return array(
        'success' => $count !== false,
        'message' => sprintf(__('%d orphaned termmeta entries deleted', 'ccm-tools'), $count ?: 0),
        'count' => $count ?: 0
    );
}

/**
 * Delete orphaned term relationships
 */
function ccm_tools_optimization_clean_orphaned_relationships() {
    global $wpdb;
    
    $count = $wpdb->query(
        "DELETE tr FROM {$wpdb->term_relationships} tr 
        LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID 
        WHERE p.ID IS NULL"
    );
    
    return array(
        'success' => $count !== false,
        'message' => sprintf(__('%d orphaned term relationships deleted', 'ccm-tools'), $count ?: 0),
        'count' => $count ?: 0
    );
}

/**
 * Get database statistics for optimization preview
 */
function ccm_tools_get_optimization_stats() {
    global $wpdb;
    
    $stats = array();
    
    // Transients count
    $stats['transients'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%_transient_%'"
    );
    
    // Spam comments
    $stats['spam_comments'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
    );
    
    // Trashed comments (>30 days)
    $stats['trashed_comments'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash' AND comment_date < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        )
    );
    
    // Trashed posts (>30 days)
    $stats['trashed_posts'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        )
    );
    
    // Auto-drafts (>7 days)
    $stats['auto_drafts'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        )
    );
    
    // Orphaned postmeta
    $stats['orphaned_postmeta'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
        LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
        WHERE p.ID IS NULL"
    );
    
    // Orphaned commentmeta
    $stats['orphaned_commentmeta'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm 
        LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID 
        WHERE c.comment_ID IS NULL"
    );
    
    // oEmbed cache entries
    $stats['oembed_cache'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '%_oembed_%'"
    );
    
    // Total revisions
    $stats['revisions'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
    );
    
    // Excess revisions (more than 5 per post)
    $stats['excess_revisions'] = 0;
    $parents = $wpdb->get_results(
        "SELECT post_parent, COUNT(*) as rev_count FROM {$wpdb->posts} 
        WHERE post_type = 'revision' AND post_parent > 0 
        GROUP BY post_parent 
        HAVING rev_count > 5"
    );
    foreach ($parents as $parent) {
        $stats['excess_revisions'] += ($parent->rev_count - 5);
    }
    
    // Orphaned termmeta
    $stats['orphaned_termmeta'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->termmeta} tm 
        LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id 
        WHERE t.term_id IS NULL"
    );
    
    // Orphaned term relationships
    $stats['orphaned_relationships'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr 
        LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID 
        WHERE p.ID IS NULL"
    );
    
    // Table count
    $stats['table_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    
    // Tables needing optimization (fragmentation ratio-based + 7-day cooldown)
    $last_optimize = (int) get_option('ccm_tools_last_db_optimize', 0);
    $optimize_cooldown = 7 * DAY_IN_SECONDS;
    if ($last_optimize > 0 && (time() - $last_optimize) < $optimize_cooldown) {
        $stats['tables_needing_optimization'] = 0;
    } else {
        $frag_condition = ccm_tools_optimize_fragmentation_condition();
        $stats['tables_needing_optimization'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND {$frag_condition}"
        );
    }
    
    // Tables needing InnoDB conversion
    $stats['tables_needing_innodb'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND ENGINE != 'InnoDB'"
    );
    
    // Tables needing collation update (not already utf8mb4_unicode_520_ci)
    $stats['tables_needing_collation'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND TABLE_COLLATION != 'utf8mb4_unicode_520_ci'"
    );
    
    // Index existence checks for each meta table
    $index_length = ccm_tools_get_safe_index_length();
    $index_name = 'ccm_meta_key';
    $meta_tables = array(
        'postmeta'    => $wpdb->postmeta,
        'usermeta'    => $wpdb->usermeta,
        'commentmeta' => $wpdb->commentmeta,
        'termmeta'    => $wpdb->termmeta,
    );
    foreach ($meta_tables as $key => $table) {
        $stats["index_{$key}_exists"] = false;
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($table_exists) {
            $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index_name}'");
            foreach ($indexes as $idx) {
                if ($idx->Sub_part == $index_length) {
                    $stats["index_{$key}_exists"] = true;
                    break;
                }
            }
        }
    }

    // Composite postmeta index check
    $stats['index_postmeta_composite_exists'] = false;
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->postmeta));
    if ($table_exists) {
        $composite_indexes = $wpdb->get_results("SHOW INDEX FROM `{$wpdb->postmeta}` WHERE Key_name = 'idx_meta_key_value_postid'");
        if (!empty($composite_indexes)) {
            $stats['index_postmeta_composite_exists'] = true;
        }
    }
    
    return $stats;
}