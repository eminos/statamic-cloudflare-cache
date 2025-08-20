<?php

namespace Eminos\StatamicCloudflareCache\Http;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Client
{
    protected string $baseUrl = 'https://api.cloudflare.com/client/v4';
    protected ?string $token;
    protected ?string $zoneId;

    public function __construct()
    {
        $this->token = config('cloudflare-cache.api_token');
        $this->zoneId = config('cloudflare-cache.zone_id');
    }

    public function purgeUrls(array $urls): bool
    {
        if (empty($urls)) {
            return false;
        }
        
        // Group URLs by zone
        $urlsByZone = $this->groupUrlsByZone($urls);
        
        if (empty($urlsByZone)) {
            // Fallback to single zone if no multi-zone config
            return $this->requestWithZone($this->zoneId, 'purge_cache', [
                'files' => $urls,
            ]);
        }
        
        // Purge each zone's URLs
        $success = true;
        foreach ($urlsByZone as $zoneId => $zoneUrls) {
            if (!$this->requestWithZone($zoneId, 'purge_cache', [
                'files' => $zoneUrls,
            ])) {
                $success = false;
            }
        }
        
        return $success;
    }

    public function purgeEverything(): bool
    {
        $zones = $this->getAllConfiguredZones();
        
        if (empty($zones)) {
            // Fallback to single zone
            return $this->requestWithZone($this->zoneId, 'purge_cache', [
                'purge_everything' => true,
            ]);
        }
        
        // Purge all configured zones
        $success = true;
        foreach ($zones as $zoneId) {
            if (!$this->requestWithZone($zoneId, 'purge_cache', [
                'purge_everything' => true,
            ])) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    public function purgeEverythingForZone(?string $zoneId = null): bool
    {
        $zoneId = $zoneId ?: $this->zoneId;
        
        return $this->requestWithZone($zoneId, 'purge_cache', [
            'purge_everything' => true,
        ]);
    }
    
    protected function groupUrlsByZone(array $urls): array
    {
        $zones = config('cloudflare-cache.zones', []);
        
        if (empty($zones)) {
            return [];
        }
        
        $urlsByZone = [];
        
        foreach ($urls as $url) {
            $zoneId = $this->getZoneForUrl($url);
            if ($zoneId) {
                $urlsByZone[$zoneId][] = $url;
            }
        }
        
        return $urlsByZone;
    }
    
    protected function getZoneForUrl(string $url): ?string
    {
        $zones = config('cloudflare-cache.zones', []);
        
        if (empty($zones)) {
            return $this->zoneId;
        }
        
        // Extract domain from URL
        $parsedUrl = parse_url($url);
        $domain = $parsedUrl['host'] ?? '';
        
        // Try to match by domain first
        if ($domain && isset($zones[$domain])) {
            return $zones[$domain];
        }
        
        // Remove www. and try again
        $domainWithoutWww = preg_replace('/^www\./', '', $domain);
        if ($domainWithoutWww && isset($zones[$domainWithoutWww])) {
            return $zones[$domainWithoutWww];
        }
        
        // Fallback to default zone
        return $this->zoneId;
    }
    
    protected function getAllConfiguredZones(): array
    {
        $zones = config('cloudflare-cache.zones', []);
        
        if (empty($zones)) {
            return [];
        }
        
        return array_unique(array_values($zones));
    }

    protected function requestWithZone(?string $zoneId, string $action, array $data): bool
    {
        if (!$this->token || !$zoneId) {
            Log::error('Cloudflare Cache: API token or Zone ID not configured', [
                'zone_id' => $zoneId,
                'has_token' => !empty($this->token),
            ]);
            return false;
        }

        if (config('cloudflare-cache.debug')) {
            Log::debug('Cloudflare Cache: Attempting API call', [
                'action' => $action,
                'zone_id' => $zoneId,
                'data' => $data,
            ]);
        }
        
        $endpoint = "{$this->baseUrl}/zones/{$zoneId}";
        
        if ($action === 'purge_cache') {
            $endpoint .= '/purge_cache';
        }
        
        try {
            $response = Http::withToken($this->token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, $data);
                
            if ($response->successful()) {
                Log::info('Cloudflare Cache: Successfully purged cache', [
                    'action' => $action,
                    'zone_id' => $zoneId,
                    'success' => $response->json('success'),
                ]);
                return true;
            }
            
            Log::error('Cloudflare Cache: Failed to purge cache', [
                'action' => $action,
                'zone_id' => $zoneId,
                'errors' => $response->json('errors'),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('Cloudflare Cache: Exception when purging cache', [
                'action' => $action,
                'zone_id' => $zoneId,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    protected function request(string $action, array $data): bool
    {
        return $this->requestWithZone($this->zoneId, $action, $data);
    }
}
