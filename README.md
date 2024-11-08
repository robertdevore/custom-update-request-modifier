# Custom Update Request Modifier

This free plugin for WordPress® modifies HTTP request user-agent strings for themes, plugins, core updates, and other WordPress API requests. 

It offers custom API URL configuration, logs request details including headers and body, and excludes plugins and themes with a custom `Update URI` in their headers from update checks.

## Features
- Modify user-agent strings for specific HTTP requests to replace the site URL with WordPress.org.
- Configure custom API URLs for monitoring HTTP requests.
- Log request headers, body, and response status codes in a custom database table.
- Exclude plugins and themes with a specified `Update URI` header from update checks.
- Supports separate update checks for plugins and themes.

## Installation
1. Clone or download the plugin to your `wp-content/plugins` directory.
2. Activate the plugin through the WordPress admin dashboard under **Plugins**.
3. Upon activation, the plugin creates a custom logging table in the database for tracking requests and schedules daily log clearing.

## Usage
### Request Modification
Once activated, the plugin intercepts HTTP requests to specific WordPress API endpoints and modifies the `user-agent` header by replacing the site's URL with `wordpress.org`. This modified header allows certain requests to appear as if they are originating from WordPress.org.

### Exclusion of Plugins and Themes
The plugin inspects the HTTP request body for plugin and theme update checks and automatically excludes items with a specified `Update URI` header. This can be useful for avoiding update checks for plugins or themes hosted outside the WordPress.org repository.

### API URL Monitoring
The plugin allows you to add custom API URLs in the settings for monitoring. Requests to these URLs will be logged, enabling detailed monitoring of specific update requests.

## Settings
Access the plugin settings under **Settings > Custom URM** in the WordPress admin dashboard. The settings page has two tabs:
1. **API URLs**: Add or remove custom API URLs for request monitoring. Each entry here is a URL that, when matched in a request, will trigger logging.
2. **Logs**: View logs of monitored HTTP requests, including details like headers and request bodies.

### Adding API URLs
- Add new URLs in the **API URLs** tab to monitor additional endpoints.
- URLs added here will be automatically checked against each HTTP request.

### Clearing Logs
- The logs tab allows clearing of log data, which can be done manually or will automatically clear daily.

## Filters

The plugin includes filters to customize various aspects of its functionality.

### User-Agent String Replacement

- **`custom_urm_user_agent_string_replace`**: Modifies the replacement value in the user-agent string.

Example usage:

```php
add_filter( 'custom_urm_user_agent_string_replace', function() {
    return 'notmatt.press';
} );
```

### Core Parameters for Update Checks

- **`custom_urm_allowed_core_params`**: Customizes the list of allowed parameters in the WordPress core update request.

Example usage:

```php
add_filter( 'custom_urm_allowed_core_params', function( $params ) {
    // Add a new parameter.
    $params[] = 'new_param';

    // Remove an existing parameter.
    $params = array_diff( $params, [ 'mysql' ] );

    return $params;
} );
```

Use `custom_urm_allowed_core_params` to control which parameters are included in the core update check request, allowing for fine-grained customization.