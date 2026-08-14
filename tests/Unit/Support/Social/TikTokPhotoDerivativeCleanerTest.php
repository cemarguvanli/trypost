<?php

declare(strict_types=1);

use App\Support\Social\TikTokPhotoDerivativeCleaner;
use Illuminate\Support\Facades\Storage;

test('it deletes only managed TikTok photo derivatives', function () {
    Storage::fake();

    $managedPaths = [
        'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg',
        'social-tiktok-photos/223e4567-e89b-12d3-a456-426614174000.webp',
    ];
    $unmanagedPaths = [
        'customer-media/123e4567-e89b-12d3-a456-426614174000.jpg',
        'social-tiktok-photos/nested/123e4567-e89b-12d3-a456-426614174000.jpg',
        'social-tiktok-photos/../customer-media/123e4567-e89b-12d3-a456-426614174000.jpg',
        'social-tiktok-photos/not-a-uuid.jpg',
    ];

    foreach ([...$managedPaths, ...$unmanagedPaths] as $path) {
        Storage::put($path, 'image');
    }

    app(TikTokPhotoDerivativeCleaner::class)->cleanup([
        'tiktok_derivative_paths' => [...$managedPaths, ...$unmanagedPaths, null, 123],
    ]);

    Storage::assertMissing($managedPaths);
    Storage::assertExists($unmanagedPaths);
});

test('it keeps derivatives while a publish_id is still in flight', function () {
    Storage::fake();

    $path = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($path, 'image');

    app(TikTokPhotoDerivativeCleaner::class)->cleanupUnlessPublishInFlight([
        'tiktok_publish_id' => 'pub_in_flight',
        'tiktok_derivative_paths' => [$path],
    ]);

    Storage::assertExists($path);
});

test('it prunes derivatives when there is no publish_id to resume', function () {
    Storage::fake();

    $path = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($path, 'image');

    app(TikTokPhotoDerivativeCleaner::class)->cleanupUnlessPublishInFlight([
        'tiktok_derivative_paths' => [$path],
    ]);

    Storage::assertMissing($path);
});

test('it prunes derivatives when the publish_id is an empty string', function () {
    Storage::fake();

    $path = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($path, 'image');

    app(TikTokPhotoDerivativeCleaner::class)->cleanupUnlessPublishInFlight([
        'tiktok_publish_id' => '',
        'tiktok_derivative_paths' => [$path],
    ]);

    Storage::assertMissing($path);
});

test('it ignores invalid retry context', function () {
    Storage::fake();

    $cleaner = app(TikTokPhotoDerivativeCleaner::class);

    $cleaner->cleanup(null);
    $cleaner->cleanup([]);
    $cleaner->cleanup(['tiktok_derivative_paths' => 'invalid']);

    Storage::assertDirectoryEmpty('/');
});
