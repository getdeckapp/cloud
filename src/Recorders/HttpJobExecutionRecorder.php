<?php

namespace Deck\Cloud\Recorders;

use Deck\Cloud\DeckCloud;
use Deck\Cloud\Events\CloudEventBuffer;
use Deck\Cloud\Events\JobExecutionIngestPayload;
use Deck\Core\Contracts\JobExecutionRecorder;
use Deck\Core\Data\JobExecutionRecord;
use Deck\Core\Events\JobExecutionRecorded;
use Deck\Core\Support\DeckResilience;

class HttpJobExecutionRecorder implements JobExecutionRecorder
{
    public function __construct(
        private readonly CloudEventBuffer $buffer,
    ) {}

    /**
     * Sink entrypoint: buffer a recorded transition for Deck Cloud ingest.
     */
    public function handle(JobExecutionRecorded $event): void
    {
        $this->record($event->record);
    }

    public function record(JobExecutionRecord $record): void
    {
        if (! DeckCloud::eventsEnabled()) {
            return;
        }

        DeckResilience::runSilentlyVoid(function () use ($record): void {
            $this->buffer->push(JobExecutionIngestPayload::fromRecord($record));
        });
    }
}
