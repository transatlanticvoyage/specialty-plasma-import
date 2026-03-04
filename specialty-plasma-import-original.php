<?php
/**
 * Plugin Name: Specialty Plasma Import
 * Description: Specialty handler for plasma content imports from React app (separate from Grove's plasma_import_mar)
 * Version: 1.0.0
 * Author: Plasma Team
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

function plasma_import_handler() {
    // Check API key
    $api_key = $_REQUEST['api_key'] ?? '';
    
    // Accept dev key or check authorization
    if ($api_key !== 'dev-key-12345' && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
        wp_die();
    }
    
    $import_type = $_REQUEST['import_type'] ?? '';
    
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
    
    // Store driggs data
    update_option('plasma_driggs_data', $driggs_data);
    
    foreach ($driggs_data as $key => $value) {
        update_option('driggs_' . $key, $value);
    }
    
    wp_send_json_success([
        'message' => 'Driggs data imported',
        'fields_count' => count($driggs_data),
        'success' => true
    ]);
}

/**
 * Get column names for the wp_pylons table
 */
function get_pylons_table_columns() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pylons';
    $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`");
    return $columns ? $columns : [];
}

/**
 * Create a pylon record following Grove's logic
 */
function create_pylon_record($post_id, $page_data) {
    global $wpdb;
    $pylons_table = $wpdb->prefix . 'pylons';
    
    // Get the schema of wp_pylons table for dynamic mapping
    $pylons_columns = get_pylons_table_columns();
    
    // Start with required relational fields - CRITICAL: use rel_wp_post_id not page_id!
    $pylon_data = [
        'rel_wp_post_id' => $post_id,
        'plasma_page_id' => isset($page_data['page_id']) ? intval($page_data['page_id']) : null
    ];
    
    // Apply explicit field mappings (for fields with different names)
    foreach (PLASMA_TO_WP_PYLONS_MAPPING as $plasma_field => $pylon_field) {
        if (isset($page_data[$plasma_field])) {
            $value = $page_data[$plasma_field];
            if (!empty($value) || $value === '0') {
                $pylon_data[$pylon_field] = $value;
            }
        }
    }
    
    // Apply explicit same-name mappings
    foreach (PLASMA_TO_WP_PYLONS_EXPLICIT as $plasma_field => $pylon_field) {
        if (isset($page_data[$plasma_field])) {
            $value = $page_data[$plasma_field];
            if (!empty($value) || $value === '0') {
                $pylon_data[$pylon_field] = $value;
            }
        }
    }
    
    // Auto-map exact column name matches (future-proof for new columns)
    foreach ($page_data as $field => $value) {
        // Skip if already mapped or if field doesn't exist in schema
        if (!isset($pylon_data[$field]) && in_array($field, $pylons_columns)) {
            if (!empty($value) || $value === '0') {
                $pylon_data[$field] = $value;
            }
        }
    }
    
    // Apply OSB rule: If page_archetype is 'homepage', set osb_is_enabled to 1
    if (isset($pylon_data['pylon_archetype']) && $pylon_data['pylon_archetype'] === 'homepage') {
        $pylon_data['osb_is_enabled'] = 1;
    }
    
    // Insert the pylon record
    $result = $wpdb->insert($pylons_table, $pylon_data);
    
    if ($result === false) {
        error_log('Specialty Plasma Import - Failed to insert pylon: ' . $wpdb->last_error);
        return false;
    }
    
    return $wpdb->insert_id;
}

function plasma_import_pages() {
    $pages_data = json_decode(stripslashes($_REQUEST['pages_data'] ?? '[]'), true);
    
    if (!is_array($pages_data)) {
        wp_send_json_error(['message' => 'Invalid pages data']);
        return;
    }
    
    global $wpdb;
    
    $created_count = 0;
    $created_pages = [];
    $pylons_count = 0;
    $errors = [];
    
    foreach ($pages_data as $page) {
        // Map plasma fields to WordPress post fields
        $post_data = [
            'post_title' => $page['page_title'] ?? 'Untitled',
            'post_content' => $page['page_content'] ?? '',
            'post_status' => $page['page_status'] ?? 'publish',
            'post_type' => $page['page_type'] ?? 'page',
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
            } else {
                $errors[] = "Failed to create pylon for post $post_id";
            }
            
            // Set homepage if needed
            if ($page['page_archetype'] === 'homepage') {
                update_option('page_on_front', $post_id);
                update_option('show_on_front', 'page');
            }
        } else {
            $errors[] = "Failed to create post: " . $post_id->get_error_message();
        }
    }
    
    wp_send_json_success([
        'message' => "Created $created_count pages with $pylons_count pylons",
        'success' => true,
        'created_count' => $created_count,
        'created_pages' => $created_pages,
        'pylons_count' => $pylons_count,  // This is what the API expects
        'pylons_created' => $pylons_count, // Also include for clarity
        'homepage_set' => get_option('page_on_front') ? true : false,
        'errors' => $errors
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
        'plugin_version' => '1.0.0'
    ], 200);
}

function plasma_rest_import($request) {
    $params = $request->get_json_params();
    
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
    
    // Handle driggs data
    if (!empty($params['driggs_data'])) {
        update_option('plasma_driggs_data', $params['driggs_data']);
        foreach ($params['driggs_data'] as $key => $value) {
            update_option('driggs_' . $key, $value);
        }
        $results['driggs_imported'] = true;
    }
    
    // Handle pages
    if (!empty($params['pages']) && is_array($params['pages'])) {
        global $wpdb;
        
        foreach ($params['pages'] as $page) {
            // Map plasma fields to WordPress post fields
            $post_data = [
                'post_title' => $page['page_title'] ?? 'Untitled',
                'post_content' => $page['page_content'] ?? '',
                'post_status' => isset($params['options']['publish']) && $params['options']['publish'] ? 'publish' : 'draft',
                'post_type' => $page['page_type'] ?? 'page',
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
                } else {
                    $results['errors'][] = "Failed to create pylon for post $post_id";
                }
                
                // Set homepage if needed
                if ($page['page_archetype'] === 'homepage' || 
                    (!empty($params['options']['set_homepage']) && $results['pages_created'] === 1)) {
                    update_option('page_on_front', $post_id);
                    update_option('show_on_front', 'page');
                }
            } else {
                $results['errors'][] = 'Failed to create page: ' . $post_id->get_error_message();
            }
        }
    }
    
    $results['success'] = $results['pages_created'] > 0 || $results['driggs_imported'];
    $results['wp_admin_url'] = admin_url('edit.php?post_type=page');
    // Also include pylons_count for compatibility with the API
    $results['pylons_count'] = $results['pylons_created'];
    
    return new WP_REST_Response($results, $results['success'] ? 200 : 500);
}

// Add admin menu
add_action('admin_menu', function() {
    add_menu_page(
        'Specialty Plasma Import',
        'Specialty Plasma',
        'manage_options',
        'specialty-plasma-import',
        'plasma_import_admin_page',
        'dashicons-upload',
        30
    );
});

function plasma_import_admin_page() {
    global $wpdb;
    $pylons_table = $wpdb->prefix . 'pylons';
    $pylons_exist = $wpdb->get_var("SHOW TABLES LIKE '$pylons_table'") === $pylons_table;
    ?>
    <div class="wrap">
        <h1>Specialty Plasma Import</h1>
        <div class="notice notice-info">
            <p>Specialty Plasma Import plugin is active and ready to receive imports from the React app.</p>
            <p><strong>Note:</strong> This is separate from Grove's plasma_import_mar page and handles direct API imports.</p>
            <p>AJAX endpoint: <code><?php echo admin_url('admin-ajax.php?action=plasma_import'); ?></code></p>
            <p>REST endpoint: <code><?php echo rest_url('plasma/v1/import'); ?></code></p>
            
            <?php if ($pylons_exist): ?>
                <p style="color: green;">✅ wp_pylons table exists and is ready for use</p>
            <?php else: ?>
                <p style="color: red;">⚠️ wp_pylons table does not exist - please ensure Ruplin plugin is active</p>
            <?php endif; ?>
        </div>
        
        <h2>Test Connection</h2>
        <button type="button" class="button button-primary" onclick="testPlasmaImport()">Test AJAX Handler</button>
        <button type="button" class="button button-primary" onclick="testPlasmaREST()">Test REST API</button>
        
        <div id="test-result" style="margin-top: 20px;"></div>
        
        <h2>Current wp_pylons Status</h2>
        <?php
        if ($pylons_exist) {
            $pylon_count = $wpdb->get_var("SELECT COUNT(*) FROM $pylons_table");
            $recent_pylons = $wpdb->get_results("SELECT pylon_id, rel_wp_post_id, plasma_page_id, pylon_archetype FROM $pylons_table ORDER BY pylon_id DESC LIMIT 5");
            echo "<p>Total pylon records: <strong>$pylon_count</strong></p>";
            
            if (!empty($recent_pylons)) {
                echo "<h3>Recent Pylons:</h3>";
                echo "<table class='wp-list-table widefat fixed striped'>";
                echo "<thead><tr><th>Pylon ID</th><th>WP Post ID</th><th>Plasma Page ID</th><th>Archetype</th></tr></thead>";
                echo "<tbody>";
                foreach ($recent_pylons as $pylon) {
                    echo "<tr>";
                    echo "<td>{$pylon->pylon_id}</td>";
                    echo "<td>{$pylon->rel_wp_post_id}</td>";
                    echo "<td>{$pylon->plasma_page_id}</td>";
                    echo "<td>{$pylon->pylon_archetype}</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            }
        }
        ?>
        
        <script>
        function testPlasmaImport() {
            jQuery.post(ajaxurl, {
                action: 'plasma_import',
                import_type: 'test',
                api_key: 'dev-key-12345'
            }, function(response) {
                jQuery('#test-result').html('<div class="notice notice-success"><p>' + JSON.stringify(response) + '</p></div>');
            });
        }
        
        function testPlasmaREST() {
            fetch('<?php echo rest_url('plasma/v1/test'); ?>')
                .then(r => r.json())
                .then(data => {
                    jQuery('#test-result').html('<div class="notice notice-success"><p>' + JSON.stringify(data) + '</p></div>');
                });
        }
        </script>
    </div>
    <?php
}