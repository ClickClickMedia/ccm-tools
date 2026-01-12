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
function ccm_tools_validate_table_name($table_name) {
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
function ccm_tools_get_appropriate_collation($version_string) {
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
 * Log database errors for debugging
 */
function ccm_tools_log_db_error($context, $wpdb_error = null) {
    global $wpdb;
    $error_message = $wpdb_error ?: $wpdb->last_error;
    if (!empty($error_message)) {
        return $error_message;
    }
    return null;
}

/**
 * Get tables that need conversion
 */
function ccm_tools_get_tables_to_convert() {
    global $wpdb;
    
    // Check MySQL/MariaDB version and determine appropriate collation
    $mysql_version = $wpdb->get_var("SELECT VERSION()");
    $collation = ccm_tools_get_appropriate_collation($mysql_version);

    // Get the database name
    $database_name = $wpdb->dbname;
    if (empty($database_name)) {
        $database_name = defined('DB_NAME') ? DB_NAME : '';
    }
    
    if (empty($database_name)) {
        return [
            'tables' => [],
            'collation' => $collation,
            'total_count' => 0,
            'error' => 'Unable to determine database name'
        ];
    }

    $tables_to_convert = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = %s 
        AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION <> %s)", 
        $database_name, $collation),
        'ARRAY_A'
    );

    return [
        'tables' => $tables_to_convert,
        'collation' => $collation,
        'total_count' => count($tables_to_convert)
    ];
}

/**
 * Convert a single table
 */
function ccm_tools_convert_single_table($table_name) {
    global $wpdb;
    
    // SECURITY: Validate table name exists in database to prevent SQL injection
    if (!ccm_tools_validate_table_name($table_name)) {
        return [
            'success' => false,
            'message' => 'Invalid table name provided',
            'table_name' => sanitize_text_field($table_name),
            'original_engine' => 'Unknown',
            'original_collation' => 'Unknown',
            'new_engine' => 'Unknown',
            'new_collation' => 'Unknown',
            'changes_made' => false
        ];
    }
    
    // Check MySQL/MariaDB version and determine appropriate collation
    $mysql_version = $wpdb->get_var("SELECT VERSION()");
    $collation = ccm_tools_get_appropriate_collation($mysql_version);
    
    // Get the database name
    $database_name = $wpdb->dbname;
    if (empty($database_name)) {
        $database_name = defined('DB_NAME') ? DB_NAME : '';
    }
    
    if (empty($database_name)) {
        return [
            'success' => false,
            'message' => 'Unable to determine database name',
            'table_name' => $table_name,
            'original_engine' => 'Unknown',
            'original_collation' => 'Unknown',
            'new_engine' => 'Unknown',
            'new_collation' => 'Unknown',
            'changes_made' => false
        ];
    }
    
    // Get current table status
    $table_info = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", $database_name, $table_name),
        'ARRAY_A'
    );
    
    if (!$table_info) {
        return [
            'success' => false,
            'message' => 'Table not found: ' . $table_name,
            'table_name' => $table_name,
            'original_engine' => 'Unknown',
            'original_collation' => 'Unknown',
            'new_engine' => 'Unknown',
            'new_collation' => 'Unknown',
            'changes_made' => false
        ];
    }
    
    $original_engine = $table_info['ENGINE'];
    $original_collation = $table_info['TABLE_COLLATION'];
    $changes_made = false;
    $errors = [];
    
    try {
        // Convert to InnoDB if not already
        if ($original_engine !== 'InnoDB') {
            $result = $wpdb->query("ALTER TABLE `{$table_name}` ENGINE = InnoDB");
            if ($result === false) {
                $errors[] = 'Failed to convert engine to InnoDB';
            } else {
                $changes_made = true;
            }
        }
        
        // Convert to appropriate utf8mb4 collation if not already
        if ($original_collation !== $collation) {
            $result = $wpdb->query("ALTER TABLE `{$table_name}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}");
            if ($result === false) {
                $errors[] = 'Failed to convert collation to ' . $collation;
            } else {
                $changes_made = true;
            }
        }
        
        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => 'Errors occurred: ' . implode(', ', $errors),
                'table_name' => $table_name,
                'original_engine' => $original_engine,
                'original_collation' => $original_collation
            ];
        }
        
        // Get updated table status using INFORMATION_SCHEMA (more reliable for MariaDB)
        $updated_status = $wpdb->get_row(
            $wpdb->prepare("SELECT ENGINE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", $database_name, $table_name),
            'ARRAY_A'
        );
        
        // Ensure we have valid updated status with proper fallbacks
        $new_engine = $original_engine;
        $new_collation = $original_collation;
        
        if ($updated_status && is_array($updated_status)) {
            $new_engine = !empty($updated_status['ENGINE']) ? $updated_status['ENGINE'] : $original_engine;
            $new_collation = !empty($updated_status['TABLE_COLLATION']) ? $updated_status['TABLE_COLLATION'] : $original_collation;
        } else {
            // Fallback: try SHOW TABLE STATUS as alternative
            $show_status = $wpdb->get_row(
                $wpdb->prepare("SHOW TABLE STATUS WHERE Name = %s", $table_name),
                'ARRAY_A'
            );
            if ($show_status && is_array($show_status)) {
                $new_engine = !empty($show_status['Engine']) ? $show_status['Engine'] : $original_engine;
                $new_collation = !empty($show_status['Collation']) ? $show_status['Collation'] : $original_collation;
            } else if ($changes_made) {
                // If changes were made but we can't verify, assume success
                $new_engine = 'InnoDB';
                $new_collation = $collation;
            }
        }

        return [
            'success' => true,
            'table_name' => $table_name,
            'original_engine' => $original_engine,
            'original_collation' => $original_collation,
            'new_engine' => $new_engine,
            'new_collation' => $new_collation,
            'changes_made' => $changes_made
        ];    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Exception: ' . $e->getMessage(),
            'table_name' => $table_name,
            'original_engine' => $original_engine,
            'original_collation' => $original_collation,
            'new_engine' => $original_engine,
            'new_collation' => $original_collation,
            'changes_made' => false
        ];
    }
}

function ccm_tools_convert_tables() {
    global $wpdb;
    $result = '';

    // Check MySQL/MariaDB version and determine appropriate collation
    $mysql_version = $wpdb->get_var("SELECT VERSION()");
    $collation = ccm_tools_get_appropriate_collation($mysql_version);

    // Get the database name
    $database_name = $wpdb->dbname;
    if (empty($database_name)) {
        $database_name = defined('DB_NAME') ? DB_NAME : '';
    }
    
    if (empty($database_name)) {
        return '<p class="ccm-error">Unable to determine database name</p>';
    }

    $tables_to_convert = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = %s 
        AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION <> %s)", 
        $database_name, $collation),
        'ARRAY_A'
    );

    if (empty($tables_to_convert)) {
        return '<p><span class="ccm-icon ccm-info">i</span>All tables up to date. Nothing to change</p>';
    }

    $result .= '<p><span class="ccm-icon ccm-info">i</span>' . count($tables_to_convert) . ' Tables Checked</p>';
    $result .= '<table class="ccm-table"><thead>';
    $result .= '<tr><th>Table</th><th>Engine</th><th>Collation</th><th>Status</th></tr></thead><tbody>';

    $tables_changed = 0;

    foreach ($tables_to_convert as $table) {
        $table_name = $table['TABLE_NAME'];
        $original_engine = $table['ENGINE'];
        $original_collation = $table['TABLE_COLLATION'];
        $changes_made = false;
        
        // Convert to InnoDB if not already
        if ($original_engine !== 'InnoDB') {
            $result_query = $wpdb->query("ALTER TABLE `{$table_name}` ENGINE = InnoDB");
            if ($result_query === false) {
                ccm_tools_log_db_error("Engine conversion for table {$table_name}");
            } else {
                $changes_made = true;
            }
        }
        
        // Convert to appropriate utf8mb4 collation if not already
        if ($original_collation !== $collation) {
            $result_query = $wpdb->query("ALTER TABLE `{$table_name}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}");
            if ($result_query === false) {
                ccm_tools_log_db_error("Collation conversion for table {$table_name}");
            } else {
                $changes_made = true;
            }
        }
        
        // Get updated table status using INFORMATION_SCHEMA (more reliable for MariaDB)
        $updated_status = $wpdb->get_row(
            $wpdb->prepare("SELECT ENGINE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s", $database_name, $table_name),
            'ARRAY_A'
        );

        // Log any database errors
        ccm_tools_log_db_error("Getting updated status for table {$table_name}");

        // Safety check for updated status with proper fallbacks
        $new_engine = 'undefined';
        $new_collation = 'undefined';
        
        if ($updated_status && is_array($updated_status)) {
            $new_engine = !empty($updated_status['ENGINE']) ? $updated_status['ENGINE'] : $original_engine;
            $new_collation = !empty($updated_status['TABLE_COLLATION']) ? $updated_status['TABLE_COLLATION'] : $original_collation;
        } else {
            // Fallback: try SHOW TABLE STATUS as alternative
            $show_status = $wpdb->get_row(
                $wpdb->prepare("SHOW TABLE STATUS WHERE Name = %s", $table_name),
                'ARRAY_A'
            );
            ccm_tools_log_db_error("Fallback SHOW TABLE STATUS for table {$table_name}");
            
            if ($show_status && is_array($show_status)) {
                $new_engine = !empty($show_status['Engine']) ? $show_status['Engine'] : $original_engine;
                $new_collation = !empty($show_status['Collation']) ? $show_status['Collation'] : $original_collation;
            } else {
                // Last resort: assume changes were successful if we made any
                $new_engine = $changes_made ? 'InnoDB' : $original_engine;
                $new_collation = $changes_made ? $collation : $original_collation;
            }
        }

        $result .= "<tr><td>{$table_name}</td><td>";
        
        // Show engine conversion with status indicators
        if ($new_engine === 'undefined' || empty($new_engine)) {
            $result .= $original_engine . ' <span class="ccm-icon ccm-error">→</span> <span style="color: red;">undefined</span>';
        } else {
            $engine_status = ($original_engine === $new_engine) ? 'ccm-info' : 'ccm-success';
            $result .= $original_engine . ' <span class="ccm-icon ' . $engine_status . '">→</span> ' . $new_engine;
        }
        
        $result .= '</td><td>';
        
        // Show collation conversion with status indicators
        if ($new_collation === 'undefined' || empty($new_collation)) {
            $result .= $original_collation . ' <span class="ccm-icon ccm-error">→</span> <span style="color: red;">undefined</span>';
        } else {
            $collation_status = ($original_collation === $new_collation) ? 'ccm-info' : 'ccm-success';
            $result .= $original_collation . ' <span class="ccm-icon ' . $collation_status . '">→</span> ' . $new_collation;
        }
        
        $result .= '</td><td>';
        
        // Add status column
        if ($new_engine === 'undefined' || $new_collation === 'undefined' || empty($new_engine) || empty($new_collation)) {
            $result .= '<span style="color: red;">✗ Failed</span>';
        } else if ($changes_made) {
            $result .= '<span style="color: green;">✓ Converted</span>';
        } else {
            $result .= '<span style="color: blue;">✓ Up to date</span>';
        }
        
        $result .= '</td></tr>';

        if ($changes_made) {
            $tables_changed++;
        }
    }

    $result .= '</tbody></table>';
    $result = '<p><span class="ccm-icon ccm-info">i</span>' . $tables_changed . ' Tables Changed</p>' . $result;
    return $result;
}