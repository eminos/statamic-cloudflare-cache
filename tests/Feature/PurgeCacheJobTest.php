<?php

namespace Eminos\StatamicCloudflareCache\Tests\Feature;

use Eminos\StatamicCloudflareCache\Events\CachePurged;
use Eminos\StatamicCloudflareCache\Http\Client;
use Eminos\StatamicCloudflareCache\Jobs\PurgeCloudflareCacheJob;
use Eminos\StatamicCloudflareCache\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class PurgeCacheJobTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/*/purge_cache' => Http::response([
                'success' => true,
            ], 200),
        ]);
    }

    #[Test]
    public function it_fires_cache_purged_event_after_purging_specific_urls()
    {
        Event::fake();
        config(['cloudflare-cache.enabled' => true]);

        $urls = ['http://test.com/page1', 'http://test.com/page2'];
        $job = new PurgeCloudflareCacheJob($urls);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldReceive('purgeUrls')->once()->with($urls);

        $job->handle($clientMock);

        Event::assertDispatched(CachePurged::class, function ($e) use ($urls) {
            return $e->urls === $urls && !$e->purgedEverything;
        });
    }

    #[Test]
    public function it_fires_cache_purged_event_after_purging_everything()
    {
        Event::fake();
        config(['cloudflare-cache.enabled' => true]);

        $job = new PurgeCloudflareCacheJob(null);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldReceive('purgeEverything')->once();

        $job->handle($clientMock);

        Event::assertDispatched(CachePurged::class, function ($e) {
            return $e->purgedEverything && empty($e->urls);
        });
    }

    #[Test]
    public function it_does_not_fire_event_when_cache_is_disabled()
    {
        Event::fake();
        config(['cloudflare-cache.enabled' => false]);

        $job = new PurgeCloudflareCacheJob(['http://test.com/page']);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldNotReceive('purgeUrls');
        $clientMock->shouldNotReceive('purgeEverything');

        $job->handle($clientMock);

        Event::assertNotDispatched(CachePurged::class);
    }
}
