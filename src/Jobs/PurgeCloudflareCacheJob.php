<?php

namespace Eminos\StatamicCloudflareCache\Jobs;

use Eminos\StatamicCloudflareCache\Events\CachePurged;
use Eminos\StatamicCloudflareCache\Http\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PurgeCloudflareCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ?array $urls;
    protected bool $purgeEverything;

    public function __construct(?array $urls = null)
    {
        $this->urls = $urls;
        $this->purgeEverything = is_null($urls);
    }

    public function handle(Client $client): void
    {
        if (! config('cloudflare-cache.enabled')) {
            if (config('cloudflare-cache.debug')) {
                Log::debug('[Cloudflare Cache] Purge skipped (disabled in config).');
            }

            return;
        }

        try {
            if ($this->purgeEverything) {
                if (config('cloudflare-cache.debug')) {
                    Log::debug('[Cloudflare Cache] Queued job purging everything.');
                }
                $client->purgeEverything();
                event(new CachePurged([], true));
            } elseif (! empty($this->urls)) {
                if (config('cloudflare-cache.debug')) {
                    Log::debug('[Cloudflare Cache] Queued job purging URLs: ' . implode(', ', $this->urls));
                }
                $client->purgeUrls($this->urls);
                event(new CachePurged($this->urls, false));
            } else {
                // This case should ideally not happen if dispatched correctly (null vs empty array)
                // but we log it just in case.
                if (config('cloudflare-cache.debug')) {
                    Log::debug('[Cloudflare Cache] Queued job skipped (no URLs provided and not purging everything).');
                }
            }
        } catch (\Exception $e) {
            Log::error('[Cloudflare Cache] Queued job failed: ' . $e->getMessage());
            // Optionally rethrow or handle failure (e.g., release back to queue)
            // $this->fail($e);
        }
    }
}
