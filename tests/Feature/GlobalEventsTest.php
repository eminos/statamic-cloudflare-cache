<?php

namespace Eminos\StatamicCloudflareCache\Tests\Feature;

use Eminos\StatamicCloudflareCache\CloudflareCacheServiceProvider;
use Eminos\StatamicCloudflareCache\Jobs\PurgeCloudflareCacheJob;
use Eminos\StatamicCloudflareCache\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\GlobalSet as GlobalSets;
use Statamic\Facades\GlobalVariables;
use Statamic\Globals\GlobalSet;
use Statamic\Globals\Variables;
use Statamic\Globals\VariablesCollection;

class GlobalEventsTest extends TestCase
{
    public static function purgeModes(): array
    {
        return ['synchronous' => [false], 'queued' => [true]];
    }

    #[Test]
    #[DataProvider('purgeModes')]
    public function saving_global_values_triggers_one_purge(bool $queued): void
    {
        // Register the real provider listeners; calling the listener directly
        // would miss duplicate purges from Statamic 5's nested save events.
        $this->app->getProvider(CloudflareCacheServiceProvider::class)->bootEvents();

        config([
            'cloudflare-cache.queue_purge' => $queued,
            'cloudflare-cache.purge_everything_fallback' => true,
        ]);

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $set = (new GlobalSet)->handle('site');
        $variables = (new Variables)->globalSet($set)->locale('en')->data(['title' => 'Updated']);

        // Stub persistence while exercising Statamic's actual save methods
        // and event dispatcher, as used by its control panel.
        GlobalSets::shouldReceive('find')->with('site')->andReturn($set);
        GlobalVariables::shouldReceive('find')->with('site::en')->andReturn($variables);
        GlobalVariables::shouldReceive('save')->once()->with($variables);
        GlobalVariables::shouldReceive('whereSet')->with('site')->andReturn(new VariablesCollection([$variables]));

        if (method_exists($set, 'addLocalization')) {
            GlobalSets::shouldReceive('save')->once()->with($set);
            $set->addLocalization($variables)->save();
        } else {
            $variables->save();
        }

        if ($queued) {
            Queue::assertPushed(PurgeCloudflareCacheJob::class, 1);
            Http::assertNothingSent();
        } else {
            Queue::assertNothingPushed();
            Http::assertSentCount(1);
            Http::assertSent(fn ($request) => $request['purge_everything'] === true);
        }
    }
}
