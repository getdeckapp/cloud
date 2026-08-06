<?php

namespace Deck\Cloud\Commands;

use Deck\Core\Blocking\JobClassBlock;
use Deck\Core\Cancellation\JobCancellation;
use Deck\Core\Cancellation\JobExecutionRetry;
use Deck\Core\Cancellation\PendingJobCancellation;
use Deck\Core\Data\JobExecutionRetryContext;
use Deck\Core\Enums\JobExecutionStatus;
use Illuminate\Support\Carbon;

/**
 * Applies remote commands pulled from Deck Cloud.
 *
 * The agent has no local database, so every operation is driven off core's
 * cache/queue primitives plus the execution context Cloud supplies in the
 * command payload (uuid, connection, queue, job_class, ...). Operations that
 * previously resolved that context from a local Eloquent lookup now expect it
 * on the payload and fail cleanly ("missing ...") when it is absent, so the
 * agent stays fully functional without a DB.
 */
class CommandApplicator
{
    public function __construct(
        private readonly JobExecutionRetry $retry = new JobExecutionRetry,
    ) {}

    public function apply(AgentCommand $command): AgentCommandResult
    {
        return match ($command->type) {
            'cancel_execution' => $this->cancelExecution($command),
            'force_cancel_execution' => $this->forceCancelExecution($command),
            'cancel_pending' => $this->cancelPending($command),
            'block_class' => $this->blockClass($command),
            'unblock_class' => $this->unblockClass($command),
            'cancel_all_running_for_class' => $this->cancelAllRunningForClass($command),
            'retry_execution' => $this->retryExecution($command),
            default => $this->failed($command->id, 'Unknown command type: '.$command->type),
        };
    }

    private function cancelExecution(AgentCommand $command): AgentCommandResult
    {
        $uuid = $this->requiredString($command->payload, 'uuid');

        if ($uuid === null) {
            return $this->failed($command->id, 'Missing uuid in command payload.');
        }

        if (JobCancellation::isCancelled($uuid)) {
            return $this->ignored($command->id);
        }

        $this->signalCancel($uuid, $command->payload, force: false);

        return $this->applied($command->id);
    }

    private function forceCancelExecution(AgentCommand $command): AgentCommandResult
    {
        $uuid = $this->requiredString($command->payload, 'uuid');

        if ($uuid === null) {
            return $this->failed($command->id, 'Missing uuid in command payload.');
        }

        if (JobCancellation::isCancelled($uuid)) {
            return $this->ignored($command->id);
        }

        $this->signalCancel($uuid, $command->payload, force: true);

        return $this->applied($command->id);
    }

    private function cancelPending(AgentCommand $command): AgentCommandResult
    {
        $uuid = $this->requiredString($command->payload, 'uuid');

        if ($uuid === null) {
            return $this->failed($command->id, 'Missing uuid in command payload.');
        }

        if (JobCancellation::isCancelled($uuid)) {
            return $this->ignored($command->id);
        }

        $connection = $this->requiredString($command->payload, 'connection');
        $queue = $this->requiredString($command->payload, 'queue');

        if ($connection === null || $queue === null) {
            return $this->failed($command->id, 'Missing connection or queue in command payload.');
        }

        PendingJobCancellation::cancel($uuid, $connection, $queue, (bool) ($command->payload['force'] ?? false));

        return $this->applied($command->id);
    }

    private function blockClass(AgentCommand $command): AgentCommandResult
    {
        $jobClass = $this->requiredString($command->payload, 'job_class');

        if ($jobClass === null) {
            return $this->failed($command->id, 'Missing job_class in command payload.');
        }

        JobClassBlock::block($jobClass, $this->optionalUntil($command->payload), $this->optionalString($command->payload, 'reason'));

        if ((bool) ($command->payload['cancel_running'] ?? true)) {
            $this->cancelTargets($command->payload);
        }

        return $this->applied($command->id);
    }

    private function unblockClass(AgentCommand $command): AgentCommandResult
    {
        $jobClass = $this->requiredString($command->payload, 'job_class');

        if ($jobClass === null) {
            return $this->failed($command->id, 'Missing job_class in command payload.');
        }

        if (! JobClassBlock::isBlocked($jobClass)) {
            return $this->ignored($command->id);
        }

        JobClassBlock::unblock($jobClass);

        return $this->applied($command->id);
    }

    private function cancelAllRunningForClass(AgentCommand $command): AgentCommandResult
    {
        $jobClass = $this->requiredString($command->payload, 'job_class');

        if ($jobClass === null) {
            return $this->failed($command->id, 'Missing job_class in command payload.');
        }

        // Without a local database the agent cannot enumerate running executions,
        // so Cloud (which has the stream) supplies the targets in the payload.
        if (! isset($command->payload['targets']) || ! is_array($command->payload['targets'])) {
            return $this->failed($command->id, 'Missing targets in command payload.');
        }

        $force = (bool) ($command->payload['force'] ?? false);

        foreach ($command->payload['targets'] as $target) {
            if (is_array($target)) {
                $this->signalCancel(
                    (string) ($target['uuid'] ?? ''),
                    $target,
                    force: $force,
                );
            }
        }

        return $this->applied($command->id);
    }

    private function retryExecution(AgentCommand $command): AgentCommandResult
    {
        $uuid = $this->requiredString($command->payload, 'uuid');
        $jobClass = $this->requiredString($command->payload, 'job_class');

        if ($uuid === null || $jobClass === null) {
            return $this->failed($command->id, 'Missing uuid or job_class in command payload.');
        }

        $result = $this->retry->retry(new JobExecutionRetryContext(
            uuid: $uuid,
            status: JobExecutionStatus::Failed,
            jobClass: $jobClass,
            connection: $this->optionalString($command->payload, 'connection') ?? '',
            queue: $this->optionalString($command->payload, 'queue') ?? '',
        ));

        if ($result->success) {
            return $this->applied($command->id);
        }

        return $this->failed($command->id, $result->message);
    }

    /**
     * Signal a single execution to cancel, removing it from the queue when it is
     * still pending (connection + queue provided) and always setting the cache
     * signal a running worker honours.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signalCancel(string $uuid, array $payload, bool $force): void
    {
        if ($uuid === '') {
            return;
        }

        $connection = $this->optionalString($payload, 'connection');
        $queue = $this->optionalString($payload, 'queue');

        if ($connection !== null && $queue !== null) {
            PendingJobCancellation::cancel($uuid, $connection, $queue, $force);

            return;
        }

        JobCancellation::cancel($uuid);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cancelTargets(array $payload): void
    {
        if (! isset($payload['targets']) || ! is_array($payload['targets'])) {
            return;
        }

        foreach ($payload['targets'] as $target) {
            if (is_array($target)) {
                $this->signalCancel((string) ($target['uuid'] ?? ''), $target, force: false);
            }
        }
    }

    private function applied(string $id): AgentCommandResult
    {
        return new AgentCommandResult(id: $id, status: 'applied');
    }

    private function ignored(string $id): AgentCommandResult
    {
        return new AgentCommandResult(id: $id, status: 'ignored');
    }

    private function failed(string $id, string $message): AgentCommandResult
    {
        return new AgentCommandResult(id: $id, status: 'failed', message: $message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload)) {
            return null;
        }

        $value = $payload[$key];

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalUntil(array $payload): ?Carbon
    {
        if (! array_key_exists('until', $payload) || $payload['until'] === null || $payload['until'] === '') {
            return null;
        }

        try {
            $until = Carbon::parse($payload['until']);

            return $until instanceof Carbon ? $until : Carbon::instance($until);
        } catch (\Throwable) {
            return null;
        }
    }
}
