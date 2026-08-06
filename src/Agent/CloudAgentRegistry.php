<?php

namespace Deck\Cloud\Agent;

use Deck\Cloud\Commands\CommandApplicator;
use Deck\Cloud\Commands\CommandPoller;
use Deck\Cloud\Connection\HttpClient;
use Deck\Cloud\DeckCloud;
use Deck\Cloud\Workers\WorkerReporter;
use Deck\Cloud\Workers\WorkerSnapshotCollector;
use Illuminate\Contracts\Foundation\Application;

/**
 * Registers Deck Cloud agent services when {@see DeckCloud::isEnabled()} is true.
 */
class CloudAgentRegistry
{
    public static function register(Application $app): void
    {
        if (! DeckCloud::isEnabled()) {
            return;
        }

        $app->singleton(HttpClient::class);
        $app->singleton(SyncThrottle::class);
        $app->singleton(WorkerSnapshotCollector::class);
        $app->singleton(WorkerReporter::class);
        $app->singleton(CommandApplicator::class);
        $app->singleton(CommandPoller::class);
        $app->singleton(AgentSync::class);
    }
}
