# Specialty Plasma Import

WordPress plugin for direct content injection from React Plasma Wizard Step 70.

## Overview

This plugin provides specialized import functionality for plasma content, separate from Grove's `plasma_import_mar` page. It handles direct API requests from the React application and ensures proper integration with WordPress and the wp_pylons system.

## Features

- **Direct Content Injection**: Handles pages and posts import via AJAX and REST API
- **Driggs Data Integration**: Stores driggs data in `wp_zen_sitespren` table (not wp_options)
- **Pylon Management**: Creates 1:1 wp_posts to wp_pylons relationships
- **Blog Post Mapping**: Maps `page_archetype='blogpost'` to `post_type='post'`
- **Date Handling**: Properly handles future/past blog post dates with appropriate `post_status`
- **Silkweaver Compatible**: Works with Silkweaver menu system
- **Separate from Grove**: Independent of Grove's plasma_import_mar functionality

## API Endpoints

### AJAX Handler
```
POST /wp-admin/admin-ajax.php
action=plasma_import
import_type=pages|driggs
api_key=dev-key-12345
```

### REST API
```
POST /wp-json/plasma/v1/import
Content-Type: application/json
X-API-Key: dev-key-12345
```

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress admin
3. Plugin will automatically handle imports from Step 70 of Plasma Wizard

## Requirements

- WordPress 5.6+ (for Application Password support)
- wp_zen_sitespren table (provided by Ruplin plugin)
- wp_pylons table (provided by Ruplin plugin)

## Database Integration

### wp_zen_sitespren
Stores driggs data for site configuration and Silkweaver menu placeholders.

### wp_pylons
Creates corresponding pylon records for each imported page/post with proper archetype mapping.

## Post Status Logic

- **Regular pages/services**: Always `post_status = 'publish'`
- **Past blog posts**: `post_status = 'publish'` with specified date
- **Future blog posts**: `post_status = 'future'` (scheduled)
- **Service pages with dates**: Ignores date, always published

## Version

1.0.0 - Initial release

## Author

Plasma Team