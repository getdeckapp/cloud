<?php

namespace Deck\Cloud\Tests;

use Deck\Cloud\DeckCloudServiceProvider;
use Deck\Core\DeckCoreServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DeckCoreServiceProvider::class,
            DeckCloudServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.redis', [
            'driver' => 'sync',
        ]);
        config()->set('cache.default', 'array');
        config()->set('deck.cancel_cache_store', 'array');
        config()->set('deck.block_cache_store', 'array');
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('deck.project', 'test');
        config()->set('deck.environment', 'testing');
        config()->set('deck.cloud.api_key', 'test-api-key');
        config()->set('deck.cloud.url', 'https://cloud.deck.test');
    }
}
