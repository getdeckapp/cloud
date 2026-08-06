<?php

use Deck\Cloud\Agent\CloudAgentRegistry;
use Deck\Cloud\Agent\SyncThrottle;
use Deck\Cloud\Tests\TestCase;
use Deck\Core\Support\DeckInstallation;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)->in('Feature', 'Unit');

function enableDeckCloudForTests(): void
{
    config()->set('deck.cloud.api_key', 'test-api-key');
    config()->set('deck.cloud.url', 'https://cloud.deck.test');
    config()->set('deck.cloud.enabled', null);
    config()->set('deck.cloud.workers.interval_seconds', 30);
    config()->set('deck.cloud.workers.enabled', true);
    config()->set('deck.cloud.commands.enabled', true);
    config()->set('deck.cloud.events.enabled', true);

    CloudAgentRegistry::register(app());
}

function resetDeckCloudSyncThrottle(): void
{
    if (app()->bound(SyncThrottle::class)) {
        app(SyncThrottle::class)->reset();
    }
}

/**
 * @param  list<array<string, mixed>>  $commands
 */
function fakeDeckCloudCommandsHttp(array $commands = []): void
{
    Http::fake([
        'https://cloud.deck.test/api/v1/agent/commands/ack' => Http::response([], 200),
        'https://cloud.deck.test/api/v1/agent/commands?*' => Http::response(['commands' => $commands]),
    ]);
}

function deckProject(): string
{
    return DeckInstallation::project();
}

function deckEnvironment(): string
{
    return DeckInstallation::environment();
}
