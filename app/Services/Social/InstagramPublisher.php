<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Models\PostPlatform;
use App\Services\Social\Concerns\CropsImageForAspectRatio;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\Meta\GraphError;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class InstagramPublisher
{
    use CropsImageForAspectRatio;
    use HasSocialHttpClient;

    private string $baseUrl;

    private const int STATUS_RETRY_DELAY_SECONDS = 10;

    private const int STATUS_MAX_RETRIES = 90;

    private const string WORKFLOW_CAROUSEL_CHILDREN = 'carousel_children';

    private const string WORKFLOW_FINAL_CONTAINER = 'final_container';

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $account = $postPlatform->socialAccount;
        $this->baseUrl = $account->platform->instagramGraphBaseUrl();

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $instagramId = $account->platform_user_id;
        $accessToken = $account->access_token;

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        $pendingWorkflow = data_get($postPlatform->error_context, 'instagram_workflow');

        if (is_array($pendingWorkflow)) {
            return $this->resumeWorkflow($instagramId, $accessToken, $content, $pendingWorkflow);
        }

        $media = $postPlatform->post->mediaItems;

        if ($media->isEmpty()) {
            throw new InstagramPublishException(
                userMessage: 'Instagram requires at least one image or video.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $firstMedia = $media->first();
        $contentType = $postPlatform->content_type;

        $aspectRatio = data_get($postPlatform->meta, 'aspect_ratio');

        return match ($contentType) {
            ContentType::InstagramReel => $this->publishReel($instagramId, $accessToken, $content, $firstMedia),
            ContentType::InstagramStory => $this->publishStory($instagramId, $accessToken, $firstMedia),
            ContentType::InstagramFeed => $this->publishFeed($instagramId, $accessToken, $content, $media, $aspectRatio),
            default => throw new InstagramPublishException(
                userMessage: "Unsupported Instagram content type: {$contentType?->value}",
                category: ErrorCategory::ContentPolicy,
            ),
        };
    }

    private function publishFeed(string $instagramId, string $accessToken, ?string $content, $media, ?string $aspectRatio): array
    {
        if ($media->count() > 1) {
            return $this->publishCarousel($instagramId, $accessToken, $content, $media, $aspectRatio);
        }

        $firstMedia = $media->first();

        if ($firstMedia->isVideo()) {
            return $this->publishReel($instagramId, $accessToken, $content, $firstMedia);
        }

        return $this->publishSingleImage($instagramId, $accessToken, $content, $firstMedia, $aspectRatio);
    }

    private function publishSingleImage(string $instagramId, string $accessToken, ?string $content, $media, ?string $aspectRatio): array
    {
        $imageUrl = $this->cropImageForAspectRatio($media->url, $aspectRatio);

        $params = [
            'image_url' => $imageUrl,
            'caption' => $content,
            'access_token' => $accessToken,
        ];

        $alt = $media->altTextFor(Platform::Instagram);

        if ($alt !== null) {
            $params['alt_text'] = $alt;
        }

        $containerId = $this->createContainer($instagramId, $params, 'container');

        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishReel(string $instagramId, string $accessToken, ?string $content, $media): array
    {
        $containerId = $this->createContainer($instagramId, [
            'video_url' => $media->url,
            'caption' => $content,
            'media_type' => 'REELS',
            'access_token' => $accessToken,
        ], 'reel container');

        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishStory(string $instagramId, string $accessToken, $media): array
    {
        $isVideo = $media->isVideo();

        $params = [
            'media_type' => 'STORIES',
            'access_token' => $accessToken,
        ];

        if ($isVideo) {
            $params['video_url'] = $media->url;
        } else {
            $dimensions = ContentType::InstagramStory->aiImageDimensions();
            $params['image_url'] = $this->fitImageToCanvas($media->url, data_get($dimensions, 'width'), data_get($dimensions, 'height'));
        }

        $containerId = $this->createContainer($instagramId, $params, 'story container');

        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishCarousel(string $instagramId, string $accessToken, ?string $content, $mediaCollection, ?string $aspectRatio): array
    {
        // Step 1: Create containers for each media item
        $childContainers = [];
        $processingChildContainers = [];

        foreach ($mediaCollection as $media) {
            $isVideo = $media->isVideo();

            $params = [
                'is_carousel_item' => 'true',
                'access_token' => $accessToken,
            ];

            if ($isVideo) {
                $params['video_url'] = $media->url;
                $params['media_type'] = 'VIDEO';
            } else {
                $params['image_url'] = $this->cropImageForAspectRatio($media->url, $aspectRatio);

                $alt = $media->altTextFor(Platform::Instagram);

                if ($alt !== null) {
                    $params['alt_text'] = $alt;
                }
            }

            $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media", $params);

            if ($containerResponse->failed()) {
                Log::error('Instagram carousel item creation failed', [
                    'body' => $this->redactResponseBody($containerResponse->body()),
                ]);

                continue;
            }

            $childId = $containerResponse->json()['id'] ?? null;

            if (! $childId) {
                Log::error('Instagram carousel item creation returned no ID', ['body' => $this->redactResponseBody($containerResponse->body())]);

                continue;
            }

            $childContainers[] = $childId;

            if ($isVideo) {
                $processingChildContainers[] = $childId;
            }
        }

        if (empty($childContainers)) {
            throw new InstagramPublishException(
                userMessage: 'Failed to create any carousel items',
                category: ErrorCategory::ServerError,
            );
        }

        return $this->finishCarousel($instagramId, $accessToken, $content, $childContainers, $processingChildContainers);
    }

    /**
     * @param  list<string>  $childContainers
     * @param  list<string>  $processingChildContainers
     */
    private function finishCarousel(string $instagramId, string $accessToken, ?string $content, array $childContainers, array $processingChildContainers): array
    {
        $workflow = [
            'stage' => self::WORKFLOW_CAROUSEL_CHILDREN,
            'child_container_ids' => $childContainers,
            'processing_child_container_ids' => $processingChildContainers,
        ];

        foreach ($processingChildContainers as $childId) {
            $this->waitForMediaProcessing($childId, $accessToken, $workflow);
        }

        $carouselId = $this->createContainer($instagramId, [
            'media_type' => 'CAROUSEL',
            'caption' => $content,
            'children' => implode(',', $childContainers),
            'access_token' => $accessToken,
        ], 'carousel container');

        return $this->finishContainer($instagramId, $accessToken, $carouselId);
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function resumeWorkflow(string $instagramId, string $accessToken, ?string $content, array $workflow): array
    {
        $stage = data_get($workflow, 'stage');

        if ($stage === self::WORKFLOW_FINAL_CONTAINER) {
            $containerId = data_get($workflow, 'container_id');

            if (is_string($containerId) && $containerId !== '') {
                return $this->finishContainer($instagramId, $accessToken, $containerId);
            }
        }

        if ($stage === self::WORKFLOW_CAROUSEL_CHILDREN) {
            $children = $this->stringList(data_get($workflow, 'child_container_ids'));
            $processingChildren = $this->stringList(data_get($workflow, 'processing_child_container_ids', []));

            if ($children !== null && $children !== [] && $processingChildren !== null) {
                return $this->finishCarousel(
                    $instagramId,
                    $accessToken,
                    $content,
                    $children,
                    $processingChildren,
                );
            }
        }

        throw new InstagramPublishException(
            userMessage: 'Instagram publish state is invalid and cannot be resumed.',
            category: ErrorCategory::ServerError,
        );
    }

    private function finishContainer(string $instagramId, string $accessToken, string $containerId): array
    {
        $this->waitForMediaProcessing($containerId, $accessToken, [
            'stage' => self::WORKFLOW_FINAL_CONTAINER,
            'container_id' => $containerId,
        ]);

        return $this->publishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishContainer(string $instagramId, string $accessToken, string $containerId): array
    {
        $publishResponse = $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media_publish", [
            'creation_id' => $containerId,
            'access_token' => $accessToken,
        ]);

        if ($publishResponse->failed()) {
            Log::error('Instagram publish failed', [
                'status' => $publishResponse->status(),
                'body' => $this->redactResponseBody($publishResponse->body()),
            ]);
            $this->handleApiError($publishResponse);
        }

        $mediaId = $publishResponse->json()['id'] ?? null;

        if (! $mediaId) {
            throw new InstagramPublishException(
                userMessage: 'Instagram publish failed: no media ID returned',
                category: ErrorCategory::ServerError,
            );
        }

        // Get permalink
        $permalinkResponse = $this->socialHttp()->get("{$this->baseUrl}/{$mediaId}", [
            'fields' => 'permalink',
            'access_token' => $accessToken,
        ]);

        $permalink = $permalinkResponse->json()['permalink'] ?? null;

        return [
            'id' => $mediaId,
            'url' => $permalink,
        ];
    }

    protected function cropFailureException(string $message): SocialPublishException
    {
        return new InstagramPublishException(
            userMessage: $message,
            category: ErrorCategory::ServerError,
        );
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function waitForMediaProcessing(string $containerId, string $accessToken, array $workflow): void
    {
        $statusResponse = $this->socialHttp()->get("{$this->baseUrl}/{$containerId}", [
            'fields' => 'status_code',
            'access_token' => $accessToken,
        ]);

        if ($statusResponse->failed()) {
            if (! GraphError::isTransientFailure($statusResponse)) {
                $this->handleApiError($statusResponse);
            }

            throw $this->pendingContainerException($containerId, $workflow, $statusResponse->status());
        }

        $status = $statusResponse->json()['status_code'] ?? 'UNKNOWN';

        if ($status === 'FINISHED') {
            return;
        }

        if ($status === 'ERROR') {
            throw new InstagramPublishException(
                userMessage: 'Instagram media processing failed',
                category: ErrorCategory::ServerError,
            );
        }

        throw $this->pendingContainerException($containerId, $workflow);
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function pendingContainerException(string $containerId, array $workflow, ?int $httpStatus = null): PlatformUnavailableException
    {
        return new PlatformUnavailableException(
            message: "Instagram is still processing container {$containerId}",
            httpStatus: $httpStatus,
            context: ['instagram_workflow' => $workflow],
            retryDelaySeconds: self::STATUS_RETRY_DELAY_SECONDS,
            maxRetries: self::STATUS_MAX_RETRIES,
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function createContainer(string $instagramId, array $parameters, string $label): string
    {
        $response = $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media", $parameters);

        if ($response->failed()) {
            Log::error("Instagram {$label} creation failed", [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $containerId = data_get($response->json(), 'id');

        if (! is_string($containerId) || $containerId === '') {
            throw new InstagramPublishException(
                userMessage: "Instagram {$label} creation failed: No container ID returned",
                category: ErrorCategory::ServerError,
            );
        }

        return $containerId;
    }

    /**
     * @return list<string>|null
     */
    private function stringList(mixed $values): ?array
    {
        return is_array($values)
            ? array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && $value !== ''))
            : null;
    }

    private function handleApiError(Response $response): never
    {
        throw InstagramPublishException::fromApiResponse($response);
    }
}
