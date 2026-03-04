<?php
/**
 * Plugin Name: Specialty Plasma Import (DEBUG VERSION)
 * Description: Debug version with extensive logging for troubleshooting pylon creation
 * Version: 1.0.1-debug
 * Author: Plasma Team
 * Last Modified: March 4, 2026 - Testing VS Code source control integration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Field mappings from Grove's implementation
define('PLASMA_TO_WP_POSTS_MAPPING', [
    'page_status' => 'post_status',
    'page_type' => 'post_type',
    'page_title' => 'post_title',
    'page_content' => 'post_content',
    'page_date' => 'post_date',
    'page_name' => 'post_name'
]);

define('PLASMA_TO_WP_PYLONS_MAPPING', [
    'page_archetype' => 'pylon_archetype'
]);

define('PLASMA_TO_WP_PYLONS_EXPLICIT', [
    'staircase_page_template_desired' => 'staircase_page_template_desired'
]);

// Register AJAX handlers
add_action('wp_ajax_plasma_import', 'plasma_import_handler');
add_action('wp_ajax_nopriv_plasma_import', 'plasma_import_handler');

function debug_log($message, $data = null) {
    $log_entry = '[SPECIALTY PLASMA DEBUG] ' . $message;
    if ($data !== null) {
        $log_entry .= ' - DATA: ' . print_r($data, true);
    }
    error_log($log_entry);
    
    // Also write to a custom log file
    $log_file = WP_CONTENT_DIR . '/specialty-plasma-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $log_entry\n", FILE_APPEND);
}

function plasma_import_handler() {
    debug_log('Handler called', $_REQUEST);
    
    // Check API key
    $api_key = $_REQUEST['api_key'] ?? '';
    
    // Accept dev key or check authorization
    if ($api_key !== 'dev-key-12345' && !current_user_can('manage_options')) {
        debug_log('Unauthorized access attempt', ['api_key' => $api_key]);
        wp_send_json_error(['message' => 'Unauthorized'], 403);
        wp_die();
    }
    
    $import_type = $_REQUEST['import_type'] ?? '';
    debug_log('Import type', $import_type);
    
    switch ($import_type) {
        case 'driggs':
            plasma_import_driggs();
            break;
        case 'pages':
            plasma_import_pages();
            break;
        case 'test':
            wp_send_json_success(['message' => 'Specialty Plasma Import plugin is working!']);
            break;
        default:
            wp_send_json_error(['message' => 'Unknown import type']);
    }
    
    wp_die();
}

function plasma_import_driggs() {
    $driggs_data = json_decode(stripslashes($_REQUEST['driggs_data'] ?? '{}'), true);
    
    if (empty($driggs_data)) {
        wp_send_json_error(['message' => 'No driggs data provided']);
        return;
    }
    
    global $wpdb;
    $sitespren_table = $wpdb->prefix . 'zen_sitespren';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$sitespren_table'") != $sitespren_table) {
        wp_send_json_error(['message' => 'wp_zen_sitespren table does not exist']);
        return;
    }
    
    // Check if the sitespren record exists (we use ID 1)
    $existing_record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $sitespren_table WHERE wppma_id = %d",
        1
    ));
    
    if ($existing_record) {
        // Get actual table columns to validate field existence
        $table_columns = $wpdb->get_col("DESCRIBE $sitespren_table");
        
        // Update existing record
        $update_data = [];
        $invalid_fields = [];
        $valid_count = 0;
        
        foreach ($driggs_data as $field => $value) {
            // Skip "id" columns - never import these
            if (strtolower($field) === 'id') {
                $invalid_fields[] = $field . ' (id columns not allowed)';
                continue;
            }
            
            // Check if column actually exists in database
            if (in_array($field, $table_columns)) {
                $update_data[$field] = $value;
                $valid_count++;
            } else {
                $invalid_fields[] = $field;
            }
        }
        
        if (!empty($update_data)) {
            $result = $wpdb->update(
                $sitespren_table,
                $update_data,
                ['wppma_id' => 1]
            );
            
            if ($result === false) {
                wp_send_json_error(['message' => 'Failed to update wp_zen_sitespren: ' . $wpdb->last_error]);
                return;
            }
        }
    } else {
        // Create new record with wppma_id = 1
        $insert_data = ['wppma_id' => 1];
        $table_columns = $wpdb->get_col("DESCRIBE $sitespren_table");
        $valid_count = 0;
        
        foreach ($driggs_data as $field => $value) {
            if (strtolower($field) !== 'id' && in_array($field, $table_columns)) {
                $insert_data[$field] = $value;
                $valid_count++;
            }
        }
        
        $result = $wpdb->insert($sitespren_table, $insert_data);
        
        if ($result === false) {
            wp_send_json_error(['message' => 'Failed to insert into wp_zen_sitespren: ' . $wpdb->last_error]);
            return;
        }
    }
    
    wp_send_json_success([
        'message' => 'Driggs data imported to wp_zen_sitespren',
        'fields_count' => $valid_count ?? count($update_data),
        'invalid_fields' => $invalid_fields ?? [],
        'success' => true
    ]);
}

/**
 * Get column names for the wp_pylons table
 */
function get_pylons_table_columns() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pylons';
    
    // Check if table exists first
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    debug_log('Table exists check', ['table' => $table_name, 'exists' => $table_exists]);
    
    if (!$table_exists) {
        debug_log('WARNING: wp_pylons table does not exist!');
        return [];
    }
    
    $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`");
    debug_log('Table columns', $columns);
    
    return $columns ? $columns : [];
}

/**
 * Create a pylon record following Grove's logic
 */
function create_pylon_record($post_id, $page_data) {
    global $wpdb;
    $pylons_table = $wpdb->prefix . 'pylons';
    
    debug_log('Creating pylon for post', ['post_id' => $post_id, 'page_id' => $page_data['page_id'] ?? 'not set']);
    
    // Get the schema of wp_pylons table for dynamic mapping
    $pylons_columns = get_pylons_table_columns();
    
    if (empty($pylons_columns)) {
        debug_log('ERROR: No columns found in pylons table or table does not exist');
        return false;
    }
    
    // Start with required relational fields - CRITICAL: use rel_wp_post_id not page_id!
    $pylon_data = [
        'rel_wp_post_id' => $post_id,
        'plasma_page_id' => isset($page_data['page_id']) ? intval($page_data['page_id']) : null
    ];
    
    debug_log('Initial pylon data', $pylon_data);
    
    // Apply explicit field mappings (for fields with different names)
    foreach (PLASMA_TO_WP_PYLONS_MAPPING as $plasma_field => $pylon_field) {
        if (isset($page_data[$plasma_field])) {
            $value = $page_data[$plasma_field];
            if (!empty($value) || $value === '0') {
                $pylon_data[$pylon_field] = $value;
                debug_log("Mapped field: $plasma_field -> $pylon_field", $value);
            }
        }
    }
    
    // Apply explicit same-name mappings
    foreach (PLASMA_TO_WP_PYLONS_EXPLICIT as $plasma_field => $pylon_field) {
        if (isset($page_data[$plasma_field])) {
            $value = $page_data[$plasma_field];
            if (!empty($value) || $value === '0') {
                $pylon_data[$pylon_field] = $value;
                debug_log("Explicit mapped field: $plasma_field", $value);
            }
        }
    }
    
    // Auto-map exact column name matches (future-proof for new columns)
    $auto_mapped_count = 0;
    foreach ($page_data as $field => $value) {
        // Skip if already mapped or if field doesn't exist in schema
        if (!isset($pylon_data[$field]) && in_array($field, $pylons_columns)) {
            if (!empty($value) || $value === '0') {
                $pylon_data[$field] = $value;
                $auto_mapped_count++;
            }
        }
    }
    debug_log("Auto-mapped fields count", $auto_mapped_count);
    
    // Apply OSB rule: If page_archetype is 'homepage', set osb_is_enabled to 1
    if (isset($pylon_data['pylon_archetype']) && $pylon_data['pylon_archetype'] === 'homepage') {
        $pylon_data['osb_is_enabled'] = 1;
        debug_log('Set osb_is_enabled for homepage');
    }
    
    debug_log('Final pylon data to insert', $pylon_data);
    
    // Insert the pylon record
    $result = $wpdb->insert($pylons_table, $pylon_data);
    
    if ($result === false) {
        debug_log('ERROR: Failed to insert pylon', [
            'last_error' => $wpdb->last_error,
            'last_query' => $wpdb->last_query
        ]);
        return false;
    }
    
    $insert_id = $wpdb->insert_id;
    debug_log('Successfully inserted pylon', ['pylon_id' => $insert_id]);
    
    return $insert_id;
}

function plasma_import_pages() {
    $pages_data = json_decode(stripslashes($_REQUEST['pages_data'] ?? '[]'), true);
    
    debug_log('Starting pages import', ['count' => count($pages_data)]);
    
    if (!is_array($pages_data)) {
        wp_send_json_error(['message' => 'Invalid pages data']);
        return;
    }
    
    global $wpdb;
    
    // Check if pylons table exists
    $pylons_table = $wpdb->prefix . 'pylons';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$pylons_table'") === $pylons_table;
    
    if (!$table_exists) {
        debug_log('CRITICAL ERROR: wp_pylons table does not exist!');
        wp_send_json_error([
            'message' => 'wp_pylons table does not exist. Please ensure Ruplin plugin is active.',
            'debug_info' => 'Table not found: ' . $pylons_table
        ]);
        return;
    }
    
    $created_count = 0;
    $created_pages = [];
    $pylons_count = 0;
    $errors = [];
    
    foreach ($pages_data as $index => $page) {
        debug_log("Processing page $index", ['title' => $page['page_title'] ?? 'untitled']);
        
        // Map plasma fields to WordPress post fields
        // IMPORTANT: Map page_archetype 'blogpost' to WordPress post_type 'post'
        $post_type = 'page'; // default
        if (isset($page['page_archetype']) && $page['page_archetype'] === 'blogpost') {
            $post_type = 'post';
        } elseif (isset($page['page_type']) && !empty($page['page_type'])) {
            $post_type = $page['page_type'];
        }
        
        // Determine post_status based on page_date for blog posts only
        $post_status = 'publish'; // Default
        
        if (!empty($page['page_status'])) {
            // Use provided page_status if available
            $post_status = $page['page_status'];
        } elseif (isset($page['page_archetype']) && $page['page_archetype'] === 'blogpost' && !empty($page['page_date'])) {
            // For blog posts with dates, check if future or past
            $page_timestamp = strtotime($page['page_date']);
            $current_timestamp = current_time('timestamp');
            
            if ($page_timestamp > $current_timestamp) {
                // Future date - schedule it
                $post_status = 'future';
            } else {
                // Past date - publish it
                $post_status = 'publish';
            }
        }
        
        $post_data = [
            'post_title' => $page['page_title'] ?? 'Untitled',
            'post_content' => $page['page_content'] ?? '',
            'post_status' => $post_status,
            'post_type' => $post_type,
            'post_name' => $page['page_name'] ?? '',
            'meta_input' => [
                'plasma_page_id' => $page['page_id'] ?? '',
                'plasma_build_id' => $page['rel_build_id'] ?? '',
                'plasma_archetype' => $page['page_archetype'] ?? ''
            ]
        ];
        
        if (!empty($page['page_date'])) {
            $post_data['post_date'] = $page['page_date'];
        }
        
        $post_id = wp_insert_post($post_data, true);
        
        if (!is_wp_error($post_id)) {
            debug_log("Created post successfully", ['post_id' => $post_id]);
            $created_count++;
            $created_pages[] = [
                'id' => $post_id,
                'title' => $post_data['post_title'],
                'url' => get_permalink($post_id),
                'edit_url' => admin_url("post.php?post=$post_id&action=edit")
            ];
            
            // Create the pylon record using Grove's logic
            $pylon_id = create_pylon_record($post_id, $page);
            if ($pylon_id) {
                $pylons_count++;
                debug_log("Pylon created successfully", ['pylon_id' => $pylon_id]);
            } else {
                $error = "Failed to create pylon for post $post_id";
                $errors[] = $error;
                debug_log("ERROR: " . $error);
            }
            
            // Set homepage if needed
            if ($page['page_archetype'] === 'homepage') {
                update_option('page_on_front', $post_id);
                update_option('show_on_front', 'page');
                debug_log("Set as homepage", ['post_id' => $post_id]);
            }
        } else {
            $error = "Failed to create post: " . $post_id->get_error_message();
            $errors[] = $error;
            debug_log("ERROR: " . $error);
        }
    }
    
    $response = [
        'message' => "Created $created_count pages with $pylons_count pylons",
        'success' => true,
        'created_count' => $created_count,
        'created_pages' => $created_pages,
        'pylons_count' => $pylons_count,  // This is what the API expects
        'pylons_created' => $pylons_count, // Also include for clarity
        'homepage_set' => get_option('page_on_front') ? true : false,
        'errors' => $errors,
        'debug_info' => [
            'table_exists' => $table_exists,
            'table_name' => $pylons_table,
            'total_processed' => count($pages_data)
        ]
    ];
    
    debug_log('Import complete', [
        'pages_created' => $created_count,
        'pylons_created' => $pylons_count,
        'errors_count' => count($errors)
    ]);
    
    wp_send_json_success($response);
}

// Test endpoint to verify plugin is working
add_action('wp_ajax_specialty_debug_test', 'specialty_debug_test');
add_action('wp_ajax_nopriv_specialty_debug_test', 'specialty_debug_test');

function specialty_debug_test() {
    global $wpdb;
    $pylons_table = $wpdb->prefix . 'pylons';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$pylons_table'") === $pylons_table;
    
    $columns = [];
    if ($table_exists) {
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$pylons_table}`");
    }
    
    wp_send_json_success([
        'message' => 'Debug plugin is active',
        'table_exists' => $table_exists,
        'table_name' => $pylons_table,
        'columns' => $columns,
        'log_file' => WP_CONTENT_DIR . '/specialty-plasma-debug.log'
    ]);
}
// Register REST API endpoints
add_action('rest_api_init', function() {
    register_rest_route('plasma/v1', '/import', [
        'methods' => 'POST',
        'callback' => 'plasma_rest_import',
        'permission_callback' => 'plasma_rest_permission_check'
    ]);
    
    register_rest_route('plasma/v1', '/test', [
        'methods' => 'GET,POST',
        'callback' => 'plasma_rest_test',
        'permission_callback' => '__return_true'
    ]);
});

function plasma_rest_permission_check($request) {
    $api_key = $request->get_header('X-API-Key') ?? $request->get_param('api_key') ?? '';
    
    if ($api_key === 'dev-key-12345') {
        return true;
    }
    
    return current_user_can('manage_options');
}

function plasma_rest_test($request) {
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Specialty Plasma REST API is working!',
        'plugin_version' => '1.0.1-debug'
    ], 200);
}

function plasma_rest_import($request) {
    $params = $request->get_json_params();
    
    debug_log('REST import called', ['pages_count' => count($params['pages'] ?? [])]);
    
    if (empty($params)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'No data provided'
        ], 400);
    }
    
    $results = [
        'success' => false,
        'pages_created' => 0,
        'pylons_created' => 0,
        'driggs_imported' => false,
        'created_pages' => [],
        'errors' => []
    ];
    
    // Handle driggs data - store in wp_zen_sitespren
    if (!empty($params['driggs_data'])) {
        global $wpdb;
        $sitespren_table = $wpdb->prefix . 'zen_sitespren';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$sitespren_table'") == $sitespren_table) {
            // Check if the sitespren record exists (we use ID 1)
            $existing_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $sitespren_table WHERE wppma_id = %d",
                1
            ));
            
            $table_columns = $wpdb->get_col("DESCRIBE $sitespren_table");
            $update_data = [];
            $valid_count = 0;
            
            foreach ($params['driggs_data'] as $field => $value) {
                // Skip "id" columns
                if (strtolower($field) === 'id') {
                    continue;
                }
                
                // Check if column exists in database
                if (in_array($field, $table_columns)) {
                    $update_data[$field] = $value;
                    $valid_count++;
                }
            }
            
            if (!empty($update_data)) {
                if ($existing_record) {
                    // Update existing record
                    $result = $wpdb->update(
                        $sitespren_table,
                        $update_data,
                        ['wppma_id' => 1]
                    );
                } else {
                    // Create new record with wppma_id = 1
                    $update_data['wppma_id'] = 1;
                    $result = $wpdb->insert($sitespren_table, $update_data);
                }
                
                if ($result !== false) {
                    $results['driggs_imported'] = true;
                    $results['driggs_fields_imported'] = $valid_count;
                    debug_log('Driggs data imported to wp_zen_sitespren via REST', ['fields' => $valid_count]);
                } else {
                    $results['errors'][] = 'Failed to update wp_zen_sitespren: ' . $wpdb->last_error;
                    debug_log('Failed to import driggs data', ['error' => $wpdb->last_error]);
                }
            }
        } else {
            $results['errors'][] = 'wp_zen_sitespren table does not exist';
            debug_log('wp_zen_sitespren table missing');
        }
    }
    
    // Handle pages
    if (!empty($params['pages']) && is_array($params['pages'])) {
        global $wpdb;
        
        foreach ($params['pages'] as $page) {
            // IMPORTANT: Map page_archetype 'blogpost' to WordPress post_type 'post'
            $post_type = 'page'; // default
            if (isset($page['page_archetype']) && $page['page_archetype'] === 'blogpost') {
                $post_type = 'post';
            } elseif (isset($page['page_type']) && !empty($page['page_type'])) {
                $post_type = $page['page_type'];
            }
            
            // Determine post_status based on page_date for blog posts only
            $post_status = 'publish'; // Default
            
            if (!empty($page['page_status'])) {
                // Use provided page_status if available
                $post_status = $page['page_status'];
            } elseif (isset($page['page_archetype']) && $page['page_archetype'] === 'blogpost' && !empty($page['page_date'])) {
                // For blog posts with dates, check if future or past
                $page_timestamp = strtotime($page['page_date']);
                $current_timestamp = current_time('timestamp');
                
                if ($page_timestamp > $current_timestamp) {
                    // Future date - schedule it
                    $post_status = 'future';
                } else {
                    // Past date - publish it
                    $post_status = 'publish';
                }
            }
            
            debug_log('Creating post via REST', ['title' => $page['page_title'] ?? 'untitled', 'type' => $post_type, 'archetype' => $page['page_archetype'] ?? 'none', 'status' => $post_status]);
            
            $post_data = [
                'post_title' => $page['page_title'] ?? 'Untitled',
                'post_content' => $page['page_content'] ?? '',
                'post_status' => $post_status,
                'post_type' => $post_type,
                'post_name' => $page['page_name'] ?? '',
                'meta_input' => [
                    'plasma_page_id' => $page['page_id'] ?? '',
                    'plasma_build_id' => $page['rel_build_id'] ?? '',
                    'plasma_archetype' => $page['page_archetype'] ?? ''
                ]
            ];
            
            if (!empty($page['page_date'])) {
                $post_data['post_date'] = $page['page_date'];
            }
            
            $post_id = wp_insert_post($post_data, true);
            
            if (!is_wp_error($post_id)) {
                $results['pages_created']++;
                $results['created_pages'][] = [
                    'id' => $post_id,
                    'title' => $post_data['post_title'],
                    'url' => get_permalink($post_id),
                    'edit_url' => admin_url("post.php?post=$post_id&action=edit")
                ];
                
                // Create the pylon record using Grove's logic
                $pylon_id = create_pylon_record($post_id, $page);
                if ($pylon_id) {
                    $results['pylons_created']++;
                    debug_log('REST: Pylon created', ['pylon_id' => $pylon_id, 'post_id' => $post_id]);
                } else {
                    $results['errors'][] = "Failed to create pylon for post $post_id";
                    debug_log('REST: Failed to create pylon', ['post_id' => $post_id]);
                }
                
                // Set homepage if needed
                if ($page['page_archetype'] === 'homepage' || 
                    (!empty($params['options']['set_homepage']) && $results['pages_created'] === 1)) {
                    update_option('page_on_front', $post_id);
                    update_option('show_on_front', 'page');
                    debug_log('REST: Set as homepage', ['post_id' => $post_id]);
                }
            } else {
                $results['errors'][] = 'Failed to create page: ' . $post_id->get_error_message();
                debug_log('REST: Failed to create post', ['error' => $post_id->get_error_message()]);
            }
        }
    }
    
    $results['success'] = $results['pages_created'] > 0 || $results['driggs_imported'];
    $results['wp_admin_url'] = admin_url('edit.php?post_type=page');
    // Also include pylons_count for compatibility with the API
    $results['pylons_count'] = $results['pylons_created'];
    
    debug_log('REST import complete', [
        'pages_created' => $results['pages_created'],
        'pylons_created' => $results['pylons_created'],
        'errors_count' => count($results['errors'])
    ]);
    
    return new WP_REST_Response($results, $results['success'] ? 200 : 500);
}
