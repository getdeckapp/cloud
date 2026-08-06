<?php

namespace Deck\Cloud;

use Deck\Cloud\Commands\PollCommandsCommand;
use Deck\Cloud\Commands\ReportWorkersCommand;
use Deck\Cloud\Concerns\RegistersCloudAgent;
use Deck\Cloud\Events\CloudEventBuffer;
use Deck\Cloud\Listeners\FlushDeckCloudEvents;
use Deck\Cloud\Recorders\HttpJobExecutionRecorder;
use Deck\Core\Events\JobExecutionRecorded;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The Deck Cloud agent: subscribes to deck/core's JobExecutionRecorded event
 * and streams executions to Deck Cloud over HTTP, reports queue workers, and
 * polls/applies remote commands. It has no database and no dashboard — install
 * it alone for a slim agent, or alongside deck/deck for the full experience.
 */
class DeckCloudServiceProvider extends ServiceProvider
{
    use RegistersCloudAgent;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/deck.php', 'deck');

        $this->app->singleton(CloudEventBuffer::class);
        $this->app->singleton(HttpJobExecutionRecorder::class);

        $this->registerCloudAgent();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PollCommandsCommand::class,
                ReportWorkersCommand::class,
            ]);
        }

        // The HTTP sink self-guards on DeckCloud::eventsEnabled() at runtime, so
        // it is always subscribed and no-ops when Cloud is disabled.
        Event::listen(JobExecutionRecorded::class, [HttpJobExecutionRecorder::class, 'handle']);

        if (DeckCloud::eventsEnabled()) {
            Event::listen(JobAttempted::class, FlushDeckCloudEvents::class);
        }

        $this->bootCloudAgent();
        $this->scheduleCloudAgent();
    }
}
