<?php

namespace Deck\Cloud\Events;

use Deck\Core\Data\ObservabilitySnapshot;

/**
 * Builds the observability fields sent to Deck Cloud from an
 * {@see ObservabilitySnapshot}. This is the shared, storage-free entry point:
 * the live sink ({@see JobExecutionIngestPayload}) calls it with the snapshot
 * carried on the recorded event, and deck/deck's backfill builds a snapshot
 * from a local Eloquent row and calls the same method.
 */
class CloudObservabilityIngestFields
{
    /**
     * @return array<string, mixed>
     */
    public static function fromSnapshot(?ObservabilitySnapshot $observability, ?int $waitMs = null): array
    {
        if ($observability === null && $waitMs === null) {
            return [];
        }

        $fields = [];

        if ($observability?->dispatchedAt !== null) {
            $fields['dispatched_at'] = $observability->dispatchedAt->utc()->toIso8601String();
        }

        if ($waitMs !== null) {
            $fields['wait_ms'] = max(0, $waitMs);
        }

        if ($observability?->dispatchGroupId !== null) {
            $fields['dispatch_group_id'] = $observability->dispatchGroupId;
        }

        if ($observability?->dispatchGroupSource !== null) {
            $fields['dispatch_group_source'] = $observability->dispatchGroupSource->value;
        }

        if ($observability?->batchId !== null) {
            $fields['batch_id'] = $observability->batchId;
        }

        if ($observability?->parentJobUuid !== null) {
            $fields['parent_job_uuid'] = $observability->parentJobUuid;
        }

        if ($observability?->parentJobClass !== null) {
            $fields['parent_job_class'] = $observability->parentJobClass;
        }

        if ($observability?->dispatchOrigin !== null && $observability->dispatchOrigin !== []) {
            $fields['dispatch_origin'] = $observability->dispatchOrigin;
        }

        return $fields;
    }
}
