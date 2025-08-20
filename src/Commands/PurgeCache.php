<?php

namespace Eminos\StatamicCloudflareCache\Commands;

use Illuminate\Console\Command;
use Eminos\StatamicCloudflareCache\Http\Client;
use Statamic\Console\RunsInPlease;

class PurgeCache extends Command
{
    use RunsInPlease;

    protected $signature = 'cloudflare:purge 
                            {--url= : Specific URL to purge}
                            {--zone= : Specific zone ID to purge (purges everything in that zone)}
                            {--domain= : Specific domain to purge (purges everything for that domain)}';

    protected $description = 'Purge Cloudflare cache';

    protected Client $client;

    public function __construct(Client $client)
    {
        parent::__construct();
        $this->client = $client;
    }

    public function handle(): int
    {
        if (!config('cloudflare-cache.enabled')) {
            $this->error('Cloudflare Cache is disabled in configuration.');
            return 1;
        }

        $url = $this->option('url');
        $zoneId = $this->option('zone');
        $domain = $this->option('domain');

        // Handle specific URL purging
        if ($url) {
            $this->info("Purging cache for URL: {$url}");
            $result = $this->client->purgeUrls([$url]);
        }
        // Handle zone-specific purging
        elseif ($zoneId) {
            $this->info("Purging all cache for zone: {$zoneId}");
            $result = $this->client->purgeEverythingForZone($zoneId);
        }
        // Handle domain-specific purging
        elseif ($domain) {
            $zones = config('cloudflare-cache.zones', []);
            $domainZoneId = $zones[$domain] ?? null;
            
            if (!$domainZoneId) {
                $this->error("No zone configured for domain: {$domain}");
                return 1;
            }
            
            $this->info("Purging all cache for domain: {$domain} (Zone: {$domainZoneId})");
            $result = $this->client->purgeEverythingForZone($domainZoneId);
        }
        // Handle purging everything
        else {
            $zones = config('cloudflare-cache.zones', []);
            
            if (!empty($zones)) {
                $this->info('Purging all Cloudflare cache across ' . count(array_unique(array_values($zones))) . ' zone(s)...');
            } else {
                $this->info('Purging all Cloudflare cache...');
            }
            
            $result = $this->client->purgeEverything();
        }

        if ($result) {
            $this->info('Cache purged successfully!');
            return 0;
        }

        $this->error('Failed to purge cache. Check logs for details.');
        return 1;
    }
}
