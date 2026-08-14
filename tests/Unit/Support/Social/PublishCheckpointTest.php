<?php

declare(strict_types=1);

use App\Support\Social\PublishCheckpoint;

test('tiktokPublishId reads a non-empty checkpoint', function () {
    expect(PublishCheckpoint::tiktokPublishId([
        PublishCheckpoint::TIKTOK_PUBLISH_ID => 'pub_in_flight',
    ]))->toBe('pub_in_flight')
        ->and(PublishCheckpoint::tiktokPublishId(['tiktok_publish_id' => '']))->toBeNull()
        ->and(PublishCheckpoint::tiktokPublishId(null))->toBeNull();
});

test('tiktokDerivativePaths returns an array or an empty list', function () {
    expect(PublishCheckpoint::tiktokDerivativePaths([
        PublishCheckpoint::TIKTOK_DERIVATIVE_PATHS => ['social-tiktok-photos/a.jpg'],
    ]))->toBe(['social-tiktok-photos/a.jpg'])
        ->and(PublishCheckpoint::tiktokDerivativePaths(['tiktok_derivative_paths' => 'invalid']))->toBe([])
        ->and(PublishCheckpoint::tiktokDerivativePaths(null))->toBe([]);
});

test('instagramWorkflow returns a non-empty array when present', function () {
    $workflow = ['stage' => 'final_container', 'container_id' => 'c1'];

    expect(PublishCheckpoint::instagramWorkflow([
        PublishCheckpoint::INSTAGRAM_WORKFLOW => $workflow,
    ]))->toBe($workflow)
        ->and(PublishCheckpoint::instagramWorkflow(['instagram_workflow' => []]))->toBeNull()
        ->and(PublishCheckpoint::instagramWorkflow(null))->toBeNull();
});
