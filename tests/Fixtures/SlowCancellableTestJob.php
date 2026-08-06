<?php

namespace Deck\Cloud\Tests\Fixtures;

use Deck\Core\Cancellation\JobCancellation;
use Deck\Core\Middleware\Cancellable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SlowCancellableTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function middleware(): array
    {
        return [new Cancellable];
    }

    public function handle(): void
    {
        for ($i = 0; $i < 5; $i++) {
            JobCancellation::throwIfCancelled($this->job);
            usleep(50_000);
        }
    }
}
