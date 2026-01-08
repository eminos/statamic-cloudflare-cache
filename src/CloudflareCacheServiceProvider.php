<?php

namespace Eminos\StatamicCloudflareCache;

use Statamic\Providers\AddonServiceProvider;
use Eminos\StatamicCloudflareCache\Commands\PurgeCache;
use Eminos\StatamicCloudflareCache\Listeners\PurgeCloudflareCache;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\TermSaved;
use Statamic\Events\TermDeleted;
use Statamic\Events\AssetSaved;
use Statamic\Events\AssetDeleted;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Events\NavTreeSaved;
use Statamic\Events\GlobalSetSaved;
use Statamic\Events\GlobalSetDeleted;

class CloudflareCacheServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        PurgeCache::class,
    ];

    protected $listen = [
        EntrySaved::class => [
            PurgeCloudflareCache::class,
        ],
        EntryDeleted::class => [
            PurgeCloudflareCache::class,
        ],
        TermSaved::class => [
            PurgeCloudflareCache::class,
        ],
        TermDeleted::class => [
            PurgeCloudflareCache::class,
        ],
        AssetSaved::class => [
            PurgeCloudflareCache::class,
        ],
        AssetDeleted::class => [
            PurgeCloudflareCache::class,
        ],
        CollectionTreeSaved::class => [
            PurgeCloudflareCache::class,
        ],
        NavTreeSaved::class => [
            PurgeCloudflareCache::class,
        ],
        GlobalSetSaved::class => [
            PurgeCloudflareCache::class,
        ],
        GlobalSetDeleted::class => [
            PurgeCloudflareCache::class,
        ],
    ];

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cloudflare-cache.php', 'cloudflare-cache'
        );
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }

        $this->publishes([
            __DIR__.'/../config/cloudflare-cache.php' => config_path('cloudflare-cache.php'),
        ], 'cloudflare-cache-config');
    }
}
