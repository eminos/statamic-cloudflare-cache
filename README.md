# Statamic Cloudflare Cache

Automatically purge your Cloudflare cache when content changes in Statamic.

## Features

- Automatically purges Cloudflare cache when Statamic content changes.
- **Multi-zone support** for Statamic multisite installations with different domains.
- Configurable events that trigger cache purging.
- Optional support for Statamic `static_caching` invalidation rules via `UrlInvalidated`/`StaticCacheCleared` events.
- Optional queuing of purge jobs for background processing.
- CLI command for manual cache purging.
- Simple configuration with backward compatibility.

## Installation

You can install the package via composer:

```bash
composer require eminos/statamic-cloudflare-cache
```

Publish the config file:

```bash
php artisan vendor:publish --tag="cloudflare-cache-config"
```

## Configuration

### Basic Setup (Single Zone)

Add the following environment variables to your `.env` file:

```
CLOUDFLARE_API_TOKEN=your-api-token
CLOUDFLARE_ZONE_ID=your-zone-id
CLOUDFLARE_CACHE_ENABLED=true
CLOUDFLARE_CACHE_QUEUE_PURGE=false # Optional: Set to true to dispatch purges to the queue
CLOUDFLARE_CACHE_DEBUG=false # Optional: Set to true to log API calls
```

### Multi-Zone Setup (Multisite)

For Statamic multisite installations with multiple domains, you can configure multiple Cloudflare zones. The package uses a single API token for all zones.

In your `config/cloudflare-cache.php` file, add your zone mappings:

```php
'zones' => [
    // Map domains to zone IDs
    'example.com' => 'zone_id_123',
    'example.fr' => 'zone_id_456',
],
```

The package will automatically detect which zone to use based on the URL being purged. It will:
1. First try to match the exact domain from the URL
2. Then try without 'www.' prefix (so 'example.com' will match 'www.example.com')
3. Fall back to the default `zone_id` if no match is found

### Getting Your Cloudflare Credentials

You can get your API token and Zone IDs from the Cloudflare dashboard:

1. **API Token**: Log in to your Cloudflare dashboard, go to "My Profile" > "API Tokens" and create a token with the "Zone.Cache Purge" permission for all zones you want to manage.

2. **Zone ID**: Go to your domain's overview page in Cloudflare. The Zone ID is displayed in the right sidebar under "API" section.

## Usage

### Automatic Cache Purging

Once configured, the addon will automatically purge the Cloudflare cache when content changes in Statamic. By default, it listens for the following events:

- Entry saved/deleted
- Term saved/deleted
- Asset saved/deleted
- Global set saved/deleted (purges everything via `purge_everything_fallback`)

You can configure which events trigger cache purging in the config file.

#### Event handling modes (either/or)

- **Default (`use_statamic_static_cache_invalidation = false`)**: uses this addon’s own content events (`entry_*`, `term_*`, `asset_*`, etc.).
- **Statamic static cache mode (`use_statamic_static_cache_invalidation = true`)**: uses Statamic Static Cache events (`url_invalidated`, `static_cache_cleared`) and skips the addon’s legacy content events.

#### Queued Purging

If you prefer to handle cache purging in the background to avoid potential delays during web requests, you can enable queued purging. Set the `CLOUDFLARE_CACHE_QUEUE_PURGE` environment variable to `true` or set `'queue_purge' => true` in the configuration file.

When enabled, purge operations triggered by events will be dispatched as jobs to your application's queue. **Note:** This requires you to have a queue worker running (`php artisan queue:work`).

### Manual Cache Purging

You can manually purge the cache using the following command:

```bash
# Purge all cache (all zones if multi-zone is configured)
php please cloudflare:purge

# Purge specific URL (automatically detects the correct zone)
php please cloudflare:purge --url=https://example.com/specific-page

# Purge specific zone by ID
php please cloudflare:purge --zone=zone_id_123

# Purge specific domain (requires multi-zone configuration)
php please cloudflare:purge --domain=example.fr
```

## Advanced Configuration

The published configuration file (`config/cloudflare-cache.php`) allows you to customize the behavior of the addon:

```php
return [
    // Enable or disable the addon
    'enabled' => env('CLOUDFLARE_CACHE_ENABLED', true),
    
    // Cloudflare API credentials
    'api_token' => env('CLOUDFLARE_API_TOKEN', ''),
    
    // Single zone configuration (backward compatibility)
    'zone_id' => env('CLOUDFLARE_ZONE_ID', ''),
    
    // Multi-zone configuration for multisite setups
    'zones' => [
        // 'domain.com' => 'zone_id_here',
        // 'another-domain.com' => 'another_zone_id',
    ],
    
    // Configure which events trigger cache purging
    'purge_on' => [
        'entry_saved' => true,
        'entry_deleted' => true,
        'term_saved' => true,
        'term_deleted' => true,
        'asset_saved' => true,
        'asset_deleted' => true,
        'collection_tree_saved' => true,
        'nav_tree_saved' => true,
        'global_set_saved' => true,
        'global_set_deleted' => true,
        'url_invalidated' => true, // Statamic Static Cache: UrlInvalidated event
        'static_cache_cleared' => true, // Statamic Static Cache: StaticCacheCleared event
    ],

    // When enabled, only Statamic Static Cache events are handled (UrlInvalidated/StaticCacheCleared).
    // Legacy addon content events are skipped in this mode.
    'use_statamic_static_cache_invalidation' => env('CLOUDFLARE_CACHE_USE_STATAMIC_STATIC_CACHE_INVALIDATION', false),

    // Dispatch purge jobs to the queue instead of running synchronously
    'queue_purge' => env('CLOUDFLARE_CACHE_QUEUE_PURGE', false),
    
    // Advanced settings
    'purge_urls' => true, // Purge specific URLs if possible
    'purge_everything_fallback' => true, // Fallback to purging everything if specific URLs can't be determined
    'debug' => env('CLOUDFLARE_CACHE_DEBUG', false), // Log API call attempts when purging
];
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
