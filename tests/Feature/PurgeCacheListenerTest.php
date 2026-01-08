<?php

namespace Eminos\StatamicCloudflareCache\Tests\Feature;

use Eminos\StatamicCloudflareCache\Tests\TestCase;
use Eminos\StatamicCloudflareCache\Http\Client;
use Eminos\StatamicCloudflareCache\Listeners\PurgeCloudflareCache;
use Statamic\Events\EntrySaved;
use Statamic\Events\GlobalSetSaved;
use Statamic\Events\GlobalSetDeleted;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Entries\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue; // Add Queue facade
use Eminos\StatamicCloudflareCache\Jobs\PurgeCloudflareCacheJob;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use stdClass; // Use stdClass for simple mock objects

class PurgeCacheListenerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/*/purge_cache' => Http::response([
                'success' => true,
            ], 200),
        ]);

        Queue::fake();
    }

    protected function mockEntry($url = '/test-entry', $collectionUrl = '/test-collection', $rootId = 'root-id')
    {
        $root = Mockery::mock(stdClass::class);
        $root->shouldReceive('id')->andReturn($rootId);

        $collection = null;
        if ($collectionUrl) {
            $collection = Mockery::mock(Collection::class);
            $collection->shouldReceive('url')->andReturn($collectionUrl);
        }

        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('url')->andReturn($url);
        $entry->shouldReceive('collection')->andReturn($collection);
        $entry->shouldReceive('root')->andReturn($root);

        return $entry;
    }

    protected function mockGlobals()
    {
        return new stdClass();
    }

    #[Test]
    public function it_purges_cache_synchronously_when_entry_is_saved_and_queue_disabled()
    {
        config(['cloudflare-cache.queue_purge' => false]);

        $entry = $this->mockEntry();
        $event = new EntrySaved($entry);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldReceive('purgeUrls')
                   ->once()
                   ->withArgs(function ($arg) {
                       return is_array($arg) && !empty($arg);
                   });
        $clientMock->shouldNotReceive('purgeEverything');

        $listener = $this->app->make(PurgeCloudflareCache::class);
        $listener->handle($event);

        Queue::assertNothingPushed();
    }


    #[Test]
    public function it_does_not_purge_cache_when_disabled()
    {
        config(['cloudflare-cache.enabled' => false]);

        $entry = $this->mockEntry();
        $event = new EntrySaved($entry);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldNotReceive('purgeUrls');
        $clientMock->shouldNotReceive('purgeEverything');

        $listener = $this->app->make(PurgeCloudflareCache::class);
        $listener->handle($event);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_falls_back_to_purge_everything_synchronously_when_configured_and_queue_disabled()
    {
        config([
            'cloudflare-cache.purge_urls' => false,
            'cloudflare-cache.purge_everything_fallback' => true,
            'cloudflare-cache.queue_purge' => false,
        ]);

        $entry = $this->mockEntry('/test-entry', null);
        $event = new EntrySaved($entry);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldReceive('purgeEverything')
                   ->once();
        $clientMock->shouldNotReceive('purgeUrls');

        $listener = $this->app->make(PurgeCloudflareCache::class);
        $listener->handle($event);

        Queue::assertNothingPushed();
    }


    #[Test]
    public function it_dispatches_job_when_queue_enabled()
    {
        config(['cloudflare-cache.queue_purge' => true]);

        $entry = $this->mockEntry('http://test.com/entry', 'http://test.com/collection');
        $event = new EntrySaved($entry);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldNotReceive('purgeUrls');
        $clientMock->shouldNotReceive('purgeEverything');

        $listener = $this->app->make(PurgeCloudflareCache::class);
        $listener->handle($event);

        Queue::assertPushed(PurgeCloudflareCacheJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $urlsProp = $reflection->getProperty('urls');
            $urlsProp->setAccessible(true);
            $urls = $urlsProp->getValue($job);

            $purgeEverythingProp = $reflection->getProperty('purgeEverything');
            $purgeEverythingProp->setAccessible(true);
            $purgeEverything = $purgeEverythingProp->getValue($job);

            return is_array($urls) && !empty($urls) && !$purgeEverything;
        });
    }


    #[Test]
    public function it_dispatches_job_to_purge_everything_when_queue_enabled_and_fallback()
    {
        config([
            'cloudflare-cache.queue_purge' => true,
            'cloudflare-cache.purge_urls' => false, // Disable specific URL purging
            'cloudflare-cache.purge_everything_fallback' => true,
        ]);

        $entry = $this->mockEntry();
        $event = new EntrySaved($entry);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldNotReceive('purgeUrls');
        $clientMock->shouldNotReceive('purgeEverything');

        $listener = $this->app->make(PurgeCloudflareCache::class);
        $listener->handle($event);

        Queue::assertPushed(PurgeCloudflareCacheJob::class, function ($job) {
             $reflection = new \ReflectionClass($job);
             $urlsProp = $reflection->getProperty('urls');
             $urlsProp->setAccessible(true);
             $urls = $urlsProp->getValue($job);

             $purgeEverythingProp = $reflection->getProperty('purgeEverything');
             $purgeEverythingProp->setAccessible(true);
             $purgeEverything = $purgeEverythingProp->getValue($job);

             return is_null($urls) && $purgeEverything;
        });
    }


     #[Test]
    public function it_does_not_dispatch_job_when_queue_enabled_but_no_urls_and_no_fallback()
    {
        config([
            'cloudflare-cache.queue_purge' => true,
            'cloudflare-cache.purge_urls' => false,
            'cloudflare-cache.purge_everything_fallback' => false,
        ]);

        $entry = $this->mockEntry(); // URLs will be generated but ignored by config
        $event = new EntrySaved($entry);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldNotReceive('purgeUrls');
        $clientMock->shouldNotReceive('purgeEverything');

        $listener = $this->app->make(PurgeCloudflareCache::class);
        $listener->handle($event);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_purges_everything_synchronously_when_global_set_is_saved_and_queue_disabled()
    {
        config([
            'cloudflare-cache.queue_purge' => false,
            'cloudflare-cache.purge_everything_fallback' => true,
            'cloudflare-cache.purge_urls' => true,
            'cloudflare-cache.purge_on.global_set_saved' => true,
        ]);

        $globals = $this->mockGlobals();
        $event = new GlobalSetSaved($globals);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldReceive('purgeEverything')->once();
        $clientMock->shouldNotReceive('purgeUrls');

        $listener = $this->app->make(PurgeCloudflareCache::class);
        $listener->handle($event);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_purges_everything_synchronously_when_global_set_is_deleted_and_queue_disabled()
    {
        config([
            'cloudflare-cache.queue_purge' => false,
            'cloudflare-cache.purge_everything_fallback' => true,
            'cloudflare-cache.purge_urls' => true,
            'cloudflare-cache.purge_on.global_set_deleted' => true,
        ]);

        $globals = $this->mockGlobals();
        $event = new GlobalSetDeleted($globals);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldReceive('purgeEverything')->once();
        $clientMock->shouldNotReceive('purgeUrls');

        $listener = $this->app->make(PurgeCloudflareCache::class);
        $listener->handle($event);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_dispatches_purge_everything_job_when_global_set_is_saved_and_queue_enabled()
    {
        config([
            'cloudflare-cache.queue_purge' => true,
            'cloudflare-cache.purge_everything_fallback' => true,
            'cloudflare-cache.purge_urls' => true,
            'cloudflare-cache.purge_on.global_set_saved' => true,
        ]);

        $globals = $this->mockGlobals();
        $event = new GlobalSetSaved($globals);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldNotReceive('purgeUrls');
        $clientMock->shouldNotReceive('purgeEverything');

        $listener = $this->app->make(PurgeCloudflareCache::class);
        $listener->handle($event);

        Queue::assertPushed(PurgeCloudflareCacheJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $urlsProp = $reflection->getProperty('urls');
            $urlsProp->setAccessible(true);
            $urls = $urlsProp->getValue($job);

            $purgeEverythingProp = $reflection->getProperty('purgeEverything');
            $purgeEverythingProp->setAccessible(true);
            $purgeEverything = $purgeEverythingProp->getValue($job);

            return is_null($urls) && $purgeEverything;
        });
    }

    #[Test]
    public function it_does_not_purge_when_global_set_saved_event_is_disabled_in_config()
    {
        config([
            'cloudflare-cache.queue_purge' => false,
            'cloudflare-cache.purge_everything_fallback' => true,
            'cloudflare-cache.purge_on.global_set_saved' => false,
        ]);

        $globals = $this->mockGlobals();
        $event = new GlobalSetSaved($globals);

        $clientMock = $this->mock(Client::class);
        $clientMock->shouldNotReceive('purgeUrls');
        $clientMock->shouldNotReceive('purgeEverything');

        $listener = $this->app->make(PurgeCloudflareCache::class);
        $listener->handle($event);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
