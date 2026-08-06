<?php

use Deck\Cloud\Events\CloudObservabilityIngestFields;
use Deck\Core\Data\ObservabilitySnapshot;
use Deck\Core\Enums\DispatchGroupSource;
use Illuminate\Support\Carbon;

it('builds cloud ingest fields from an observability snapshot', function (): void {
    $dispatchedAt = Carbon::parse('2026-05-25T14:02:01Z');

    $fields = CloudObservabilityIngestFields::fromSnapshot(
        new ObservabilitySnapshot(
            dispatchedAt: $dispatchedAt,
            dispatchGroupId: 'req-7f3a2b1c',
            dispatchGroupSource: DispatchGroupSource::Request,
            parentJobUuid: '550e8400-e29b-41d4-a716-446655440000',
            parentJobClass: 'App\\Jobs\\ParentJob',
            dispatchOrigin: [
                'type' => 'http',
                'method' => 'POST',
                'route' => 'orders.store',
            ],
        ),
        waitMs: 3000,
    );

    expect($fields)->toMatchArray([
        'dispatched_at' => '2026-05-25T14:02:01+00:00',
        'wait_ms' => 3000,
        'dispatch_group_id' => 'req-7f3a2b1c',
        'dispatch_group_source' => 'request',
        'parent_job_uuid' => '550e8400-e29b-41d4-a716-446655440000',
        'parent_job_class' => 'App\\Jobs\\ParentJob',
        'dispatch_origin' => [
            'type' => 'http',
            'method' => 'POST',
            'route' => 'orders.store',
        ],
    ]);
});

it('returns an empty array when no observability data is present', function (): void {
    expect(CloudObservabilityIngestFields::fromSnapshot(null))->toBe([]);
});
