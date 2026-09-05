<?php

use Deck\Cloud\Agent\AgentSync;
use Deck\Cloud\Agent\SyncThrottle;
use Deck\Cloud\Commands\CommandPoller;
use Deck\Cloud\DeckCloud;
use Deck\Cloud\Tests\Fixtures\SuccessfulTestJob;
use Deck\Core\Blocking\JobClassBlock;
use Deck\Core\Cancellation\JobCancellation;
use Deck\Core\Pausing\QueuePause;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * The agent has no local database, so remote commands carry their execution
 * context (uuid, connection, queue, job_class, targets) in the payload and are
 * applied through core's cache/queue primitives. These tests exercise that
 * DB-free path end to end via the poller.
 */

beforeEach(function () {
    enableDeckCloudForTests();
    config()->set('deck.project', 'billing-api');
    config()->set('deck.environment', 'production');

    resetDeckCloudSyncThrottle();
});

/**
 * @param  array<string, mixed>  $payload
 */
function fakeAgentCommand(string $id, string $type, array $payload): void
{
    Http::fake([
        'https://cloud.deck.test/api/v1/agent/commands*' => Http::response([
            'commands' => [
                [
                    'id' => $id,
                    'type' => $type,
                    'project' => 'billing-api',
                    'environment' => 'production',
                    'issued_at' => now()->toIso8601String(),
                    'payload' => $payload,
                ],
            ],
        ]),
        'https://cloud.deck.test/api/v1/agent/commands/ack' => Http::response([], 200),
    ]);
}

function ackStatus(): ?string
{
    $status = null;

    Http::assertSent(function ($request) use (&$status) {
        if ($request->url() === 'https://cloud.deck.test/api/v1/agent/commands/ack') {
            $status = $request->data()['results'][0]['status'] ?? null;

            return true;
        }

        return false;
    });

    return $status;
}

it('is disabled when cloud is not enabled', function () {
    config()->set('deck.cloud.enabled', false);

    expect(DeckCloud::commandsEnabled())->toBeFalse();
});

it('pulls, applies and acks cancel execution commands', function () {
    $uuid = (string) str()->uuid();

    fakeAgentCommand('cmd_cancel_1', 'cancel_execution', [
        'uuid' => $uuid,
        'attempt' => 1,
        'connection' => 'redis',
        'queue' => 'default',
    ]);

    app(CommandPoller::class)->poll();

    expect(JobCancellation::isCancelled($uuid))->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && str_contains($request->url(), '/api/v1/agent/commands')
            && $request['project'] === 'billing-api'
            && $request['environment'] === 'production';
    });

    expect(ackStatus())->toBe('applied');
});

it('applies force cancel execution commands', function () {
    $uuid = (string) str()->uuid();

    fakeAgentCommand('cmd_force_1', 'force_cancel_execution', [
        'uuid' => $uuid,
        'attempt' => 1,
        'connection' => 'redis',
        'queue' => 'default',
    ]);

    app(CommandPoller::class)->poll();

    expect(JobCancellation::isCancelled($uuid))->toBeTrue()
        ->and(ackStatus())->toBe('applied');
});

it('applies cancel pending commands', function () {
    $uuid = (string) str()->uuid();

    fakeAgentCommand('cmd_pending_1', 'cancel_pending', [
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'default',
        'force' => false,
    ]);

    app(CommandPoller::class)->poll();

    expect(JobCancellation::isCancelled($uuid))->toBeTrue();
});

it('acks failed when the payload is missing required context', function () {
    fakeAgentCommand('cmd_fail_1', 'cancel_execution', [
        // no uuid — the agent has no database to resolve it from
        'attempt' => 1,
    ]);

    app(CommandPoller::class)->poll();

    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.deck.test/api/v1/agent/commands/ack'
        && $request['results'][0]['status'] === 'failed'
        && str_contains($request['results'][0]['message'], 'Missing uuid'));
});

it('acks ignored when the same cancel command is applied twice', function () {
    $uuid = (string) str()->uuid();
    JobCancellation::cancel($uuid);

    fakeAgentCommand('cmd_dup_1', 'cancel_pending', [
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'default',
    ]);

    app(CommandPoller::class)->poll();

    expect(ackStatus())->toBe('ignored');
});

it('deduplicates duplicate command ids in a single pull batch', function () {
    $uuid = (string) str()->uuid();

    Http::fake([
        'https://cloud.deck.test/api/v1/agent/commands*' => Http::response([
            'commands' => [
                [
                    'id' => 'cmd_dup_batch',
                    'type' => 'cancel_pending',
                    'project' => 'billing-api',
                    'environment' => 'production',
                    'issued_at' => now()->toIso8601String(),
                    'payload' => ['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default'],
                ],
                [
                    'id' => 'cmd_dup_batch',
                    'type' => 'cancel_pending',
                    'project' => 'billing-api',
                    'environment' => 'production',
                    'issued_at' => now()->toIso8601String(),
                    'payload' => ['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default'],
                ],
            ],
        ]),
        'https://cloud.deck.test/api/v1/agent/commands/ack' => Http::response([], 200),
    ]);

    app(CommandPoller::class)->poll();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://cloud.deck.test/api/v1/agent/commands/ack'
            && count($request->data()['results'] ?? []) === 1;
    });
});

it('polls commands when worker sync is throttled', function () {
    $poller = Mockery::mock(CommandPoller::class);
    $poller->shouldReceive('poll')->once();
    app()->instance(CommandPoller::class, $poller);

    $throttle = Mockery::mock(SyncThrottle::class);
    $throttle->shouldReceive('shouldSync')->with('workers', 'redis:default')->andReturnFalse();
    $throttle->shouldReceive('shouldSync')->with('commands', 'installation')->andReturnTrue();
    app()->instance(SyncThrottle::class, $throttle);

    app(AgentSync::class)->syncQueueWorker('redis', 'default');
});

it('polls commands after worker reporting on the same sync tick', function () {
    Http::fake([
        'https://cloud.deck.test/api/v1/ingest/workers' => Http::response(['accepted' => 0], 202),
        'https://cloud.deck.test/api/v1/agent/commands*' => Http::response(['commands' => []]),
    ]);

    app(AgentSync::class)->syncQueueWorker('redis', 'default');

    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/api/v1/agent/commands'));
});

it('applies block class commands from cloud', function () {
    $jobClass = SuccessfulTestJob::class;

    fakeAgentCommand('cmd_block_1', 'block_class', [
        'job_class' => $jobClass,
        'cancel_running' => false,
    ]);

    app(CommandPoller::class)->poll();

    expect(JobClassBlock::isBlocked($jobClass))->toBeTrue()
        ->and(ackStatus())->toBe('applied');
});

it('applies timed block class commands from cloud', function () {
    $jobClass = SuccessfulTestJob::class;
    $until = now()->addHour()->toIso8601String();

    fakeAgentCommand('cmd_block_timed', 'block_class', [
        'job_class' => $jobClass,
        'until' => $until,
        'cancel_running' => false,
    ]);

    app(CommandPoller::class)->poll();

    expect(JobClassBlock::isBlocked($jobClass))->toBeTrue()
        ->and(JobClassBlock::blockedUntil($jobClass)?->toIso8601String())->toBe($until);
});

it('applies unblock class commands from cloud', function () {
    $jobClass = SuccessfulTestJob::class;

    JobClassBlock::block($jobClass, now()->addHour());

    fakeAgentCommand('cmd_unblock_1', 'unblock_class', [
        'job_class' => $jobClass,
    ]);

    app(CommandPoller::class)->poll();

    expect(JobClassBlock::isBlocked($jobClass))->toBeFalse();
});

it('applies cancel all running for class commands from cloud using payload targets', function () {
    $uuid = (string) str()->uuid();

    fakeAgentCommand('cmd_cancel_class_1', 'cancel_all_running_for_class', [
        'job_class' => 'App\\Jobs\\SyncInvoices',
        'targets' => [
            ['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default'],
        ],
    ]);

    app(CommandPoller::class)->poll();

    expect(JobCancellation::isCancelled($uuid))->toBeTrue()
        ->and(ackStatus())->toBe('applied');
});

it('acks failed for cancel all running when no targets are supplied', function () {
    fakeAgentCommand('cmd_cancel_class_notargets', 'cancel_all_running_for_class', [
        'job_class' => 'App\\Jobs\\SyncInvoices',
    ]);

    app(CommandPoller::class)->poll();

    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.deck.test/api/v1/agent/commands/ack'
        && $request['results'][0]['status'] === 'failed'
        && str_contains($request['results'][0]['message'], 'targets'));
});

it('acks failed for unknown command types', function () {
    fakeAgentCommand('cmd_unknown', 'restart_supervisor', []);

    app(CommandPoller::class)->poll();

    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.deck.test/api/v1/agent/commands/ack'
        && $request['results'][0]['status'] === 'failed'
        && str_contains($request['results'][0]['message'], 'Unknown command type'));
});

it('does not poll commands when command sync is disabled', function () {
    config()->set('deck.cloud.commands.enabled', false);

    Http::fake([
        'https://cloud.deck.test/api/v1/ingest/workers' => Http::response(['accepted' => 0], 202),
    ]);

    app(AgentSync::class)->syncQueueWorker('redis', 'default');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v1/agent/commands'));
});

it('applies retry execution commands using payload context', function () {
    fakeAgentCommand('cmd_retry_1', 'retry_execution', [
        'uuid' => (string) str()->uuid(),
        'job_class' => SuccessfulTestJob::class,
        'connection' => 'redis',
        'queue' => 'default',
    ]);

    app(CommandPoller::class)->poll();

    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.deck.test/api/v1/agent/commands/ack'
        && $request['results'][0]['id'] === 'cmd_retry_1'
        && in_array($request['results'][0]['status'], ['applied', 'failed'], true));
});

it('applies pause queue commands and holds workers on that queue idle', function () {
    fakeAgentCommand('cmd_pause_1', 'pause_queue', [
        'connection' => 'redis',
        'queue' => 'default',
        'reason' => 'Draining before deploy',
    ]);

    app(CommandPoller::class)->poll();

    expect(QueuePause::isPaused('redis', 'default'))->toBeTrue()
        ->and(QueuePause::audit('redis', 'default')?->reason)->toBe('Draining before deploy')
        ->and(Event::until(new Looping('redis', 'default')))->toBeFalse()
        ->and(Event::until(new Looping('redis', 'emails')))->toBeNull()
        ->and(ackStatus())->toBe('applied');
});

it('applies resume queue commands and releases the workers', function () {
    QueuePause::pause('redis', 'default');

    fakeAgentCommand('cmd_resume_1', 'resume_queue', [
        'connection' => 'redis',
        'queue' => 'default',
    ]);

    app(CommandPoller::class)->poll();

    expect(QueuePause::isPaused('redis', 'default'))->toBeFalse()
        ->and(Event::until(new Looping('redis', 'default')))->toBeNull()
        ->and(ackStatus())->toBe('applied');
});

it('acks pause and resume as applied even when already in that state', function () {
    fakeAgentCommand('cmd_resume_idle', 'resume_queue', [
        'connection' => 'redis',
        'queue' => 'default',
    ]);

    app(CommandPoller::class)->poll();

    expect(ackStatus())->toBe('applied');
});

it('acks failed for pause queue commands missing the queue identity', function () {
    fakeAgentCommand('cmd_pause_bad', 'pause_queue', [
        'connection' => 'redis',
    ]);

    app(CommandPoller::class)->poll();

    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.deck.test/api/v1/agent/commands/ack'
        && $request['results'][0]['status'] === 'failed'
        && str_contains($request['results'][0]['message'], 'Missing connection or queue'));

    expect(QueuePause::isPaused('redis', 'default'))->toBeFalse();
});
