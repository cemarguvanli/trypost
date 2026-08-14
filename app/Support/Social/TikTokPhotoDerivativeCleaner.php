<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class TikTokPhotoDerivativeCleaner
{
    private const string DIRECTORY = 'social-tiktok-photos';

    /**
     * @param  array<string, mixed>|null  $context
     */
    public function cleanup(?array $context, ?string $postPlatformId = null): void
    {
        $paths = data_get($context, 'tiktok_derivative_paths', []);

        if (! is_array($paths)) {
            return;
        }

        $this->cleanupPaths($paths, $postPlatformId);
    }

    /**
     * Keep hosted photos while a publish_id can still be resumed.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function cleanupUnlessPublishInFlight(?array $context, ?string $postPlatformId = null): void
    {
        $publishId = data_get($context, 'tiktok_publish_id');

        if (is_string($publishId) && $publishId !== '') {
            return;
        }

        $this->cleanup($context, $postPlatformId);
    }

    /**
     * @param  array<array-key, mixed>  $paths
     */
    public function cleanupPaths(array $paths, ?string $postPlatformId = null): void
    {
        $derivativePaths = array_values(array_filter(
            $paths,
            $this->isManagedDerivativePath(...),
        ));

        if ($derivativePaths === []) {
            return;
        }

        try {
            Storage::delete($derivativePaths);
        } catch (Throwable $e) {
            Log::warning('Failed to prune TikTok photo derivatives', [
                'post_platform_id' => $postPlatformId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isManagedDerivativePath(mixed $path): bool
    {
        return is_string($path)
            && dirname($path) === self::DIRECTORY
            && Str::isUuid(pathinfo($path, PATHINFO_FILENAME));
    }
}
