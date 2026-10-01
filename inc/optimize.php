<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/* c045137c46cbe031 */

/* 84af77c2ef1dfe13 */
function ccm_tools_get_safe_index_length() {
    return 191; // Safe for utf8mb4 (191 * 4 = 764 bytes, under 767 byte limit)
}

/* 3497b3af94e2c0de */
function ccm_tools_validate_table_name_optimize($table_name) {
    return ccm_tools_validate_table_name($table_name);
}

/* db71b7688fa654e0 */
function ccm_tools_get_appropriate_collation_optimize($version_string = '') {
    return ccm_tools_get_appropriate_collation($version_string);
}

/* a141f445c3b06567 */

/* 14cad9a669dd4751 */
function ccm_tools_get_post_delete_batch_size() {
    $size = (int) apply_filters('ccm_tools_post_delete_batch_size', 200);

    return $size > 0 ? $size : 200;
}

/* 94eb26ab7f621d4c */
function ccm_tools_is_safe_sql_identifier($identifier) {
    return is_string($identifier) && $identifier !== '' && preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
}

/* d871a5c6291090cf */
function ccm_tools_group_index_rows($rows) {
    $grouped = array();

    foreach ((array) $rows as $row) {
        if (!is_object($row) || !isset($row->Key_name)) {
            continue;
        }
        $key_name = (string) $row->Key_name;
        if (!isset($grouped[$key_name])) {
            $grouped[$key_name] = array();
        }
        $grouped[$key_name][] = $row;
    }

    return $grouped;
}

/* 2e4a332d567b643e */
function ccm_tools_index_is_single_column($parts, $column) {
    if (!is_array($parts) || count($parts) !== 1) {
        return false;
    }

    $part = reset($parts);

    return is_object($part)
        && isset($part->Column_name)
        && (string) $part->Column_name === (string) $column;
}

/* 264b72e50d8fac00 */
function ccm_tools_find_droppable_indexes($rows, $column, $keep_names = array()) {
    $keep = array();
    foreach ((array) $keep_names as $name) {
        $keep[] = (string) $name;
    }

    $droppable = array();

    foreach (ccm_tools_group_index_rows($rows) as $key_name => $parts) {
        if ($key_name === 'PRIMARY' || in_array($key_name, $keep, true)) {
            continue;
        }
        if (!ccm_tools_index_is_single_column($parts, $column)) {
            continue;
        }
        $droppable[] = $key_name;
    }

    return $droppable;
}

/* 8b770ed020c9145d */
function ccm_tools_delete_ids_in_chunks($table, $column, $ids, $chunk_size = 500) {
    global $wpdb;

    $report = array('affected' => 0, 'failed' => 0);

    $ids = array_values(array_unique(array_map('intval', (array) $ids)));

    if (empty($ids)) {
        return $report;
    }

    if (!ccm_tools_is_safe_sql_identifier($table) || !ccm_tools_is_safe_sql_identifier($column)) {
        $report['failed'] = count($ids);
        return $report;
    }

    $chunk_size = (int) $chunk_size;
    if ($chunk_size < 1) {
        $chunk_size = 500;
    }

    foreach (array_chunk($ids, $chunk_size) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $result = $wpdb->query(
            $wpdb->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})", $chunk)
        );

        if ($result === false) {
            ccm_tools_log_db_error("Chunked delete from {$table}");
            $report['failed'] += count($chunk);
        } else {
            $report['affected'] += (int) $result;
        }
    }

    return $report;
}

/* 1cd2226ca210326e */
function ccm_tools_delete_posts_by_status_batch($post_status, $modified_before_gmt, $batch_size = 0) {
    global $wpdb;

    $batch_size = (int) $batch_size;
    if ($batch_size < 1) {
        $batch_size = ccm_tools_get_post_delete_batch_size();
    }

    $report = array('matched' => 0, 'deleted' => 0, 'failed' => 0, 'remaining' => 0);

    $report['matched'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_modified < %s",
            $post_status,
            $modified_before_gmt
        )
    );

    if ($report['matched'] < 1) {
        return $report;
    }

    if (!function_exists('wp_delete_post')) {
        // There is no sane fallback: a raw DELETE is the very bug this
        // function exists to remove, so report the work as undone.
        $report['failed'] = $report['matched'];
        $report['remaining'] = $report['matched'];
        return $report;
    }

    $post_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_modified < %s ORDER BY ID ASC LIMIT %d",
            $post_status,
            $modified_before_gmt,
            $batch_size
        )
    );

    /* db25ed167016c787 */
    $defer_terms = function_exists('wp_defer_term_counting');
    $defer_comments = function_exists('wp_defer_comment_counting');
    if ($defer_terms) {
        wp_defer_term_counting(true);
    }
    if ($defer_comments) {
        wp_defer_comment_counting(true);
    }

    foreach ((array) $post_ids as $post_id) {
        // force_delete = true: these rows are being purged, not re-trashed.
        if (wp_delete_post((int) $post_id, true)) {
            $report['deleted']++;
        } else {
            $report['failed']++;
        }
    }

    if ($defer_terms) {
        wp_defer_term_counting(false);
    }
    if ($defer_comments) {
        wp_defer_comment_counting(false);
    }

    $report['remaining'] = max(0, $report['matched'] - $report['deleted']);

    return $report;
}

/* 6b263fe12030787d */
function ccm_tools_ensure_postmeta_meta_key_index() {
    global $wpdb;

    $steps = array();
    $index_name = 'ccm_meta_key';
    $column = 'meta_key';
    $index_length = (int) ccm_tools_get_safe_index_length();
    $table = $wpdb->postmeta;

    // Read EVERY index part on the table, not just the rows that mention this
    // column, so composites can be recognised and left alone.
    $index_rows = $wpdb->get_results("SHOW INDEX FROM {$table}");
    $grouped = ccm_tools_group_index_rows($index_rows);

    $ours = isset($grouped[$index_name]) ? $grouped[$index_name] : array();
    $ours_exists = !empty($ours);
    $ours_is_ours = $ours_exists && ccm_tools_index_is_single_column($ours, $column);
    $ours_correct = false;

    if ($ours_is_ours) {
        $part = reset($ours);
        $ours_correct = ((int) $part->Sub_part === $index_length);
    }

    if ($ours_exists && !$ours_is_ours) {
        /* 6f17bf134b5dd696 */
        $steps[] = array(
            'ok' => false,
            'message' => sprintf(
                __('An index named %1$s already exists on %2$s covering more than %3$s — left untouched', 'ccm-tools'),
                $index_name,
                $table,
                $column
            ),
        );
        return $steps;
    }

    /* cc6cf593fb11e0ba */
    $droppable = ccm_tools_find_droppable_indexes($index_rows, $column, array($index_name));

    if ($ours_exists && !$ours_correct) {
        $droppable[] = $index_name;
    }

    foreach ($droppable as $drop_name) {
        if (!ccm_tools_is_safe_sql_identifier($drop_name)) {
            continue;
        }
        $dropped = $wpdb->query("ALTER TABLE {$table} DROP INDEX `{$drop_name}`");
        if ($dropped === false) {
            $error_msg = ccm_tools_log_db_error("Drop index {$drop_name} from {$table}");
            $steps[] = array(
                'ok' => false,
                'message' => sprintf(__('Failed to remove index %1$s from %2$s', 'ccm-tools'), $drop_name, $table)
                    . ($error_msg ? ': ' . $error_msg : ''),
            );
        } else {
            $steps[] = array(
                'ok' => true,
                'message' => sprintf(__('Existing single-column index %1$s removed from %2$s', 'ccm-tools'), $drop_name, $table),
            );
        }
    }

    if ($ours_correct) {
        $steps[] = array(
            'ok' => true,
            'message' => sprintf(
                __('Index %1$s already exists on %2$s with the correct size of %3$d', 'ccm-tools'),
                $index_name,
                $table,
                $index_length
            ),
        );
        return $steps;
    }

    $added = $wpdb->query("ALTER TABLE {$table} ADD INDEX `{$index_name}` (`{$column}`({$index_length}))");

    if ($added === false) {
        $error_msg = ccm_tools_log_db_error("Add {$index_name} index on {$table}");
        $steps[] = array(
            'ok' => false,
            'message' => sprintf(__('Failed to add index %1$s on %2$s', 'ccm-tools'), $index_name, $table)
                . ($error_msg ? ': ' . $error_msg : ''),
        );
    } else {
        $steps[] = array(
            'ok' => true,
            'message' => sprintf(
                __('Index %1$s added on %2$s with size %3$d', 'ccm-tools'),
                $index_name,
                $table,
                $index_length
            ),
        );
    }

    return $steps;
}

/* 100b699535171b89 */
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
            'risk' => 'moderate'
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

/* ca6a3b6b29f1ced6 */
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

/* c37258f1a664222d */
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

/* f7e71c554214bdfc */
function ccm_tools_optimize_initial_setup() {
    global $wpdb;
    $results = [];
    
    try {
        /* 9b12734800f4a8b5 */
        $index_failed = false;
        foreach (ccm_tools_ensure_postmeta_meta_key_index() as $step) {
            $results[] = $step['message'];
            if (empty($step['ok'])) {
                $index_failed = true;
            }
        }
        if ($index_failed) {
            $results[] = 'Warning: Some index changes could not be applied';
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
    $errors = array();

    // Check MySQL/MariaDB version and determine appropriate collation
    $mysql_version = $wpdb->get_var("SELECT VERSION()");
    $collation = ccm_tools_get_appropriate_collation_optimize($mysql_version);

    /* 8358cf66761400fb */
    foreach (ccm_tools_ensure_postmeta_meta_key_index() as $step) {
        if (empty($step['ok'])) {
            $errors[] = $step['message'];
            $result .= '<p><span class="ccm-icon ccm-error">✗</span>' . esc_html($step['message']) . '</p>';
        } else {
            $result .= '<p><span class="ccm-icon ccm-success">✓</span>' . esc_html($step['message']) . '</p>';
        }
    }

    // Delete transients
    $transient_report = ccm_tools_clear_all_transients();
    foreach ($transient_report['details'] as $detail) {
        $icon_class = stripos($detail, 'Failed') !== false || stripos($detail, 'Warning') !== false ? 'ccm-warning' : 'ccm-info';
        $result .= '<p><span class="ccm-icon ' . $icon_class . '">i</span>' . esc_html($detail) . '</p>';
    }
    if (!$transient_report['success']) {
        $result .= '<p><span class="ccm-icon ccm-warning">!</span>Some transient entries could not be removed</p>';
        $errors[] = 'Some transient entries could not be removed';
    }

    // Optimize tables
    $tables = $wpdb->get_results("SHOW TABLES", 'ARRAY_N');

    // Get database name for INFORMATION_SCHEMA queries
    $database_name = $wpdb->dbname;
    if (empty($database_name)) {
        $database_name = defined('DB_NAME') ? DB_NAME : '';
    }

    $tables_optimized = 0;
    $tables_failed = 0;

    foreach ($tables as $table) {
        $table_name = $table[0];

        // SECURITY: Validate table name exists in database to prevent SQL injection
        if (!ccm_tools_validate_table_name_optimize($table_name)) {
            $errors[] = "{$table_name}: Invalid table name — skipped for safety";
            $result .= '<p><span class="ccm-icon ccm-warning">!</span>' . esc_html($table_name) . ' skipped (invalid table name)</p>';
            $tables_failed++;
            continue;
        }

        $optimize_result = $wpdb->query("OPTIMIZE TABLE `{$table_name}`");
        if ($optimize_result === false) {
            $error_msg = ccm_tools_log_db_error("Optimize table {$table_name}");
            $errors[] = "{$table_name}: OPTIMIZE TABLE failed" . ($error_msg ? ': ' . $error_msg : '');
            $result .= '<p><span class="ccm-icon ccm-error">✗</span>' . esc_html($table_name) . ' optimize failed' . ($error_msg ? ': ' . esc_html($error_msg) : '') . '</p>';
            $tables_failed++;
        } else {
            $result .= '<p><span class="ccm-icon ccm-success">✓</span>' . esc_html($table_name) . ' optimized</p>';
            $tables_optimized++;
        }

        // Update table collation if necessary - use INFORMATION_SCHEMA for MariaDB compatibility
        if (!empty($database_name)) {
            $table_status = $wpdb->get_row(
                $wpdb->prepare("SELECT TABLE_COLLATION as Collation FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", $database_name, $table_name),
                'OBJECT'
            );

            if ($table_status && $table_status->Collation !== $collation) {
                $collation_result = $wpdb->query("ALTER TABLE `{$table_name}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}");
                if ($collation_result === false) {
                    $error_msg = ccm_tools_log_db_error("Collation update for table {$table_name}");
                    $errors[] = "{$table_name}: collation update failed" . ($error_msg ? ': ' . $error_msg : '');
                    $result .= '<p><span class="ccm-icon ccm-error">✗</span>' . esc_html($table_name) . ' collation update failed' . ($error_msg ? ': ' . esc_html($error_msg) : '') . '</p>';
                } else {
                    $result .= '<p><span class="ccm-icon ccm-success">✓</span>' . esc_html($table_name) . ' collation updated to ' . esc_html($collation) . '</p>';
                }
            }
        }
    }

    if (!empty($errors)) {
        $error_list = '<div class="ccm-alert ccm-alert-error" style="margin-bottom: 1rem;"><strong>' . count($errors) . ' ' . __('error(s) occurred:', 'ccm-tools') . '</strong><ul>';
        foreach ($errors as $error) {
            $error_list .= '<li>' . esc_html($error) . '</li>';
        }
        $error_list .= '</ul></div>';
        $result = $error_list . $result;
    }

    return $result;
}

/* 0ae8b939bc9d7386 */

/* b095ba5032b61464 */
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

/* d5a1e8911b30c833 */
function ccm_tools_optimization_clear_transients() {
    $report = ccm_tools_clear_all_transients();
    return array(
        'success' => $report['success'],
        'message' => implode('; ', $report['details']),
        'count' => $report['total_removed']
    );
}

/* f6a3096b08a2bf10 */
function ccm_tools_optimize_fragmentation_condition() {
    return '(Data_free > 5242880 AND Data_free > 0.2 * (Data_length + Index_length))';
}

/* d9ef06ad74835060 */
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

/* 2a84fca54288a5bd */
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

/* c32ae77bcd1dc90d */
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

/* 51bb24ff6c30bb1a */
function ccm_tools_optimization_clean_spam_comments() {
    global $wpdb;

    /* 3ade401ab5874b1f */
    $ids = $wpdb->get_col("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam'");

    if (empty($ids)) {
        return array(
            'success' => true,
            'message' => __('No spam comments to delete', 'ccm-tools'),
            'count'   => 0,
        );
    }

    // Meta first: once the comment row is gone the ids are harder to justify.
    ccm_tools_delete_ids_in_chunks($wpdb->commentmeta, 'comment_id', $ids);
    $report = ccm_tools_delete_ids_in_chunks($wpdb->comments, 'comment_ID', $ids);

    return array(
        'success' => empty($report['failed']),
        'message' => sprintf(
            /* translators: %d: number of comments actually deleted */
            __('%d spam comments deleted', 'ccm-tools'),
            (int) $report['affected']
        ),
        'count'   => (int) $report['affected'],
    );
}

/* d8ff3668c971c788 */
function ccm_tools_optimization_clean_trashed_comments() {
    global $wpdb;
    
    /* 6db15b7e5ca16e5e */
    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash' AND comment_date < %s",
            gmdate('Y-m-d H:i:s', strtotime('-30 days'))
        )
    );

    if (empty($ids)) {
        return array(
            'success' => true,
            'message' => __('No trashed comments older than 30 days', 'ccm-tools'),
            'count'   => 0,
        );
    }

    ccm_tools_delete_ids_in_chunks($wpdb->commentmeta, 'comment_id', $ids);
    $report = ccm_tools_delete_ids_in_chunks($wpdb->comments, 'comment_ID', $ids);

    return array(
        'success' => empty($report['failed']),
        'message' => sprintf(
            /* translators: %d: number of comments actually deleted */
            __('%d trashed comments deleted (>30 days old)', 'ccm-tools'),
            (int) $report['affected']
        ),
        'count'   => (int) $report['affected'],
    );
}

/* 75c1cc6b645022aa */
function ccm_tools_format_post_sweep_result($report, $deleted_label) {
    $message = $deleted_label;

    if ($report['failed'] > 0) {
        $message .= sprintf(__(', %d could not be deleted', 'ccm-tools'), $report['failed']);
    }

    if ($report['remaining'] > 0) {
        $message .= sprintf(__(', %d still to go — run this again', 'ccm-tools'), $report['remaining']);
    }

    return array(
        // Real deletions only. This used to report the number of rows found
        // and claim success even when every DELETE had failed.
        'success' => $report['failed'] === 0,
        'message' => $message,
        'count' => $report['deleted'],
        'remaining' => $report['remaining'],
    );
}

/* ad6fa4ccb7cf54b7 */
function ccm_tools_optimization_clean_trashed_posts() {
    $report = ccm_tools_delete_posts_by_status_batch(
        'trash',
        gmdate('Y-m-d H:i:s', strtotime('-30 days'))
    );

    return ccm_tools_format_post_sweep_result(
        $report,
        sprintf(__('%d trashed posts deleted (>30 days old)', 'ccm-tools'), $report['deleted'])
    );
}

/* 931bc966e8d2fab3 */
function ccm_tools_optimization_clean_auto_drafts() {
    $report = ccm_tools_delete_posts_by_status_batch(
        'auto-draft',
        gmdate('Y-m-d H:i:s', strtotime('-7 days'))
    );

    return ccm_tools_format_post_sweep_result(
        $report,
        sprintf(__('%d auto-drafts deleted (>7 days old)', 'ccm-tools'), $report['deleted'])
    );
}

/* df485a09409a4623 */
function ccm_tools_optimization_add_postmeta_index() {
    global $wpdb;
    
    $index_length = ccm_tools_get_safe_index_length();
    $index_name = 'ccm_meta_key';
    
    return ccm_tools_add_meta_index($wpdb->postmeta, 'meta_key', $index_name, $index_length);
}

/* 1e37436383e51ee6 */
function ccm_tools_optimization_add_usermeta_index() {
    global $wpdb;
    
    $index_length = ccm_tools_get_safe_index_length();
    $index_name = 'ccm_meta_key';
    
    return ccm_tools_add_meta_index($wpdb->usermeta, 'meta_key', $index_name, $index_length);
}

/* dabe1634dd585d34 */
function ccm_tools_optimization_add_commentmeta_index() {
    global $wpdb;
    
    $index_length = ccm_tools_get_safe_index_length();
    $index_name = 'ccm_meta_key';
    
    return ccm_tools_add_meta_index($wpdb->commentmeta, 'meta_key', $index_name, $index_length);
}

/* e981099a3289eb10 */
function ccm_tools_optimization_add_termmeta_index() {
    global $wpdb;
    
    $index_length = ccm_tools_get_safe_index_length();
    $index_name = 'ccm_meta_key';
    
    return ccm_tools_add_meta_index($wpdb->termmeta, 'meta_key', $index_name, $index_length);
}

/* 6550cbba602028bc */
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

/* 3e9ccc6d48080824 */
function ccm_tools_add_meta_index($table, $column, $index_name, $index_length) {
    global $wpdb;

    $index_length = (int) $index_length;

    // The column and index names are interpolated, so they must be bare
    // identifiers. Every caller passes a literal, and this keeps it that way.
    if (!ccm_tools_is_safe_sql_identifier($column)
        || !ccm_tools_is_safe_sql_identifier($index_name)
        || $index_length < 1) {
        return array(
            'success' => false,
            'message' => __('Invalid index definition', 'ccm-tools'),
            'count' => 0
        );
    }

    // Check if table exists. The name comes from $wpdb, never from input.
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    if (!$table_exists) {
        return array(
            'success' => false,
            'message' => sprintf(__('Table %s does not exist', 'ccm-tools'), $table),
            'count' => 0
        );
    }

    // Read every index part on the table, then group by Key_name. Only a
    // grouped view can tell a single-column index from a composite.
    $index_rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`");
    $grouped = ccm_tools_group_index_rows($index_rows);

    $ours = isset($grouped[$index_name]) ? $grouped[$index_name] : array();
    $ours_exists = !empty($ours);
    $ours_is_ours = $ours_exists && ccm_tools_index_is_single_column($ours, $column);
    $ours_correct = false;

    if ($ours_is_ours) {
        $part = reset($ours);
        $ours_correct = ((int) $part->Sub_part === $index_length);
    }

    if ($ours_exists && !$ours_is_ours) {
        // The name is taken by an index covering more than this column. Leave
        // it alone rather than drop a composite someone else depends on.
        return array(
            'success' => false,
            'message' => sprintf(
                __('An index named %1$s already exists on %2$s covering more than %3$s — left untouched', 'ccm-tools'),
                $index_name,
                $table,
                $column
            ),
            'count' => 0
        );
    }

    if ($ours_correct) {
        return array(
            'success' => true,
            'message' => sprintf(__('Index already exists on %s with correct size (%d)', 'ccm-tools'), $table, $index_length),
            'count' => 0
        );
    }

    // Only single-part indexes on this exact column are candidates. PRIMARY is
    // excluded, and so is every composite, whatever column it starts with.
    $droppable = ccm_tools_find_droppable_indexes($index_rows, $column, array($index_name));

    // Ours exists but at the wrong size — replace it.
    if ($ours_exists) {
        $droppable[] = $index_name;
    }

    $drop_failed = 0;
    $dropped_names = array();

    foreach ($droppable as $drop_name) {
        if (!ccm_tools_is_safe_sql_identifier($drop_name)) {
            continue;
        }
        $dropped = $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `{$drop_name}`");
        if ($dropped === false) {
            ccm_tools_log_db_error("Drop index {$drop_name} from {$table}");
            $drop_failed++;
        } else {
            $dropped_names[] = $drop_name;
        }
    }

    $result = $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`{$column}`({$index_length}))");

    if ($result === false) {
        ccm_tools_log_db_error("Add {$index_name} index on {$table}");

        return array(
            'success' => false,
            'message' => sprintf(__('Failed to add index to %s', 'ccm-tools'), $table),
            'count' => 0
        );
    }

    $message = sprintf(__('Index added to %s (%d chars)', 'ccm-tools'), $table, $index_length);

    if (!empty($dropped_names)) {
        $message .= sprintf(
            __(', replaced redundant single-column index: %s', 'ccm-tools'),
            implode(', ', $dropped_names)
        );
    }

    if ($drop_failed > 0) {
        $message .= sprintf(__(', %d redundant index could not be dropped', 'ccm-tools'), $drop_failed);
    }

    return array(
        'success' => $drop_failed === 0,
        'message' => $message,
        'count' => 1
    );
}

/* 2715016907447fb9 */
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

/* a3083d0f280b2346 */
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

/* b9f30f4b39dc6b8c */
function ccm_tools_optimization_clean_oembed_cache() {
    global $wpdb;

    $count = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('_oembed_') . '%'
        )
    );
    
    return array(
        'success' => $count !== false,
        'message' => sprintf(__('%d oEmbed cache entries cleared', 'ccm-tools'), $count ?: 0),
        'count' => $count ?: 0
    );
}

/* 2593bcce365cadc9 */
function ccm_tools_optimization_limit_revisions() {
    global $wpdb;
    
    $keep_count = 5;

    // Get all parent posts that have revisions
    $parents = $wpdb->get_col(
        "SELECT DISTINCT post_parent FROM {$wpdb->posts}
        WHERE post_type = 'revision' AND post_parent > 0"
    );

    $to_delete = array();

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

        // Everything after the first 5 goes
        foreach (array_slice($revisions, $keep_count) as $revision_id) {
            $to_delete[] = (int) $revision_id;
        }
    }

    /* 9c8457842d055cc6 */
    $posts_report = ccm_tools_delete_ids_in_chunks($wpdb->posts, 'ID', $to_delete);
    $meta_report = ccm_tools_delete_ids_in_chunks($wpdb->postmeta, 'post_id', $to_delete);

    $deleted = $posts_report['affected'];
    $failed = $posts_report['failed'] + $meta_report['failed'];

    $message = sprintf(__('%d excess revisions deleted (kept %d per post)', 'ccm-tools'), $deleted, $keep_count);
    if ($failed > 0) {
        $message .= sprintf(__(', %d rows could not be deleted', 'ccm-tools'), $failed);
    }

    return array(
        // Based on rows the database says it actually removed, not on how
        // many ids were handed to it.
        'success' => $failed === 0,
        'message' => $message,
        'count' => $deleted
    );
}

/* 0261dbc21e55c5ea */
function ccm_tools_optimization_delete_all_revisions() {
    global $wpdb;

    // Get all revision IDs
    $revision_ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'"
    );

    $found = count($revision_ids);

    $posts_report = ccm_tools_delete_ids_in_chunks($wpdb->posts, 'ID', $revision_ids);
    $meta_report = ccm_tools_delete_ids_in_chunks($wpdb->postmeta, 'post_id', $revision_ids);

    $deleted = $posts_report['affected'];
    $failed = $posts_report['failed'] + $meta_report['failed'];

    $message = sprintf(__('%d revisions permanently deleted', 'ccm-tools'), $deleted);
    if ($failed > 0) {
        $message .= sprintf(__(', %d rows could not be deleted', 'ccm-tools'), $failed);
    } elseif ($deleted < $found) {
        $message .= sprintf(__(' (%d were found)', 'ccm-tools'), $found);
    }

    return array(
        // Real affected rows, reported by the database itself.
        'success' => $failed === 0,
        'message' => $message,
        'count' => $deleted
    );
}

/* 6ca633779b6563de */
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

/* 70282dd8dcd95310 */
function ccm_tools_optimization_clean_orphaned_relationships() {
    global $wpdb;
    
    /* f71880fe58fba4eb */
    $taxonomies = function_exists('get_object_taxonomies') && function_exists('get_post_types')
        ? get_object_taxonomies(get_post_types(array(), 'names'), 'names')
        : array();

    if (empty($taxonomies)) {
        return array(
            'success' => true,
            'message' => __('No post taxonomies are registered, so nothing was removed', 'ccm-tools'),
            'count'   => 0,
        );
    }

    $placeholders = implode(', ', array_fill(0, count($taxonomies), '%s'));

    $count = $wpdb->query(
        $wpdb->prepare(
            "DELETE tr FROM {$wpdb->term_relationships} tr"
            . " INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id"
            . " LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID"
            . " WHERE p.ID IS NULL AND tt.taxonomy IN ({$placeholders})",
            array_values($taxonomies)
        )
    );

    return array(
        'success' => $count !== false,
        'message' => sprintf(
            /* translators: %d: number of relationships deleted */
            __('%d orphaned term relationships deleted', 'ccm-tools'),
            (int) ($count ?: 0)
        ),
        'count'   => (int) ($count ?: 0),
    );
}

/* 9aa809cb7dcd9fe0 */
function ccm_tools_db_overview(): array {
    global $wpdb;

    $out = array(
        'size_bytes'     => 0,
        'overhead_bytes' => 0,
        'rows'           => 0,
        'tables'         => 0,
        'engines'        => array(),
        'collations'     => array(),
        'largest'        => array(),
        'autoload_bytes' => 0,
        'autoload_count' => 0,
        'prefix'         => $wpdb->prefix,
        'server'         => '',
    );

    $version = $wpdb->get_var('SELECT VERSION()');
    $out['server'] = is_string($version) ? $version : '';

    /* 8e2281aaab158ec1 */
    $tables = $wpdb->get_results(
        "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS,
                (DATA_LENGTH + INDEX_LENGTH) AS total_bytes,
                DATA_FREE AS free_bytes
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
          ORDER BY total_bytes DESC"
    );

    if (!is_array($tables)) {
        return $out;
    }

    foreach ($tables as $t) {
        $out['tables']++;
        $out['size_bytes']     += (int) $t->total_bytes;
        $out['overhead_bytes'] += (int) $t->free_bytes;
        $out['rows']           += (int) $t->TABLE_ROWS;

        $engine = $t->ENGINE ? $t->ENGINE : 'unknown';
        $out['engines'][$engine] = isset($out['engines'][$engine]) ? $out['engines'][$engine] + 1 : 1;

        $collation = $t->TABLE_COLLATION ? $t->TABLE_COLLATION : 'unknown';
        $out['collations'][$collation] = isset($out['collations'][$collation])
            ? $out['collations'][$collation] + 1
            : 1;

        if (count($out['largest']) < 8) {
            $out['largest'][] = array(
                'name'      => (string) $t->TABLE_NAME,
                'bytes'     => (int) $t->total_bytes,
                'free'      => (int) $t->free_bytes,
                'rows'      => (int) $t->TABLE_ROWS,
                'engine'    => $engine,
                'collation' => $collation,
                // A table outside this install's prefix belongs to something
                // else sharing the database, and is not ours to tidy.
                'foreign'   => strpos((string) $t->TABLE_NAME, $wpdb->prefix) !== 0,
            );
        }
    }

    /* cfeeb42f721d7bb3 */
    $autoload = $wpdb->get_row(
        "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes
           FROM {$wpdb->options}
          WHERE autoload IN ('yes', 'on')"
    );
    if ($autoload) {
        $out['autoload_count'] = (int) $autoload->n;
        $out['autoload_bytes'] = (int) $autoload->bytes;
    }

    return $out;
}

/* 4efc1391db0f1666 */
function ccm_tools_db_autoload_worst(int $limit = 5): array {
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, LENGTH(option_value) AS bytes
               FROM {$wpdb->options}
              WHERE autoload IN ('yes', 'on')
              ORDER BY bytes DESC
              LIMIT %d",
            $limit
        )
    );

    return is_array($rows) ? $rows : array();
}

/* d69f8a0e37d6efc5 */
function ccm_tools_get_optimization_stats() {
    global $wpdb;
    
    $stats = array();
    
    // Transients count
    /* 06d80fb87a2a0324 */
    $stats['transients'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            /* 3952787a0856997a */
            "SELECT COUNT(*) FROM {$wpdb->options}"
            . " WHERE option_name LIKE %s ESCAPE '\\\\' OR option_name LIKE %s ESCAPE '\\\\'",
            '\_transient\_%',
            '\_site_transient\_%'
        )
    );
    
    // Spam comments
    $stats['spam_comments'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
    );
    
    // Trashed comments (>30 days)
    $stats['trashed_comments'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash' AND comment_date < %s",
            gmdate('Y-m-d H:i:s', strtotime('-30 days'))
        )
    );
    
    // Trashed posts (>30 days)
    $stats['trashed_posts'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified < %s",
            gmdate('Y-m-d H:i:s', strtotime('-30 days'))
        )
    );
    
    // Auto-drafts (>7 days)
    $stats['auto_drafts'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified < %s",
            gmdate('Y-m-d H:i:s', strtotime('-7 days'))
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
    
    /* dc1fad8460f8a96a */
    $stats['oembed_cache'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('_oembed_') . '%'
        )
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
    
    // Orphaned term relationships. Counted with the same post-taxonomy
    // restriction the sweep applies, so the figure describes the click.
    $rel_taxonomies = function_exists('get_object_taxonomies') && function_exists('get_post_types')
        ? get_object_taxonomies(get_post_types(array(), 'names'), 'names')
        : array();

    if (empty($rel_taxonomies)) {
        $stats['orphaned_relationships'] = 0;
    } else {
        $rel_placeholders = implode(', ', array_fill(0, count($rel_taxonomies), '%s'));
        $stats['orphaned_relationships'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr"
                . " INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id"
                . " LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID"
                . " WHERE p.ID IS NULL AND tt.taxonomy IN ({$rel_placeholders})",
                array_values($rel_taxonomies)
            )
        );
    }
    
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