<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::PartiallyPublished,
        'published_at' => now()->subHour(),
    ]);
});

test('it does not expose retry filters or confirmation bypasses', function () {
    $command = Artisan::all()['posts:retry'];

    expect($command->getDefinition()->hasOption('force'))->toBeFalse()
        ->and($command->getDefinition()->hasOption('platform'))->toBeFalse();
});

test('it queues fresh attempts only for failed enabled platforms', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $publishedPlatform = PostPlatform::factory()->published()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);
    $failedThreads = PostPlatform::factory()->threads()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->threads()->create([
            'workspace_id' => $this->workspace->id,
        ]),
        'platform_post_id' => 'stale-post-id',
        'platform_url' => 'https://threads.net/stale',
        'published_at' => now()->subHour(),
        'error_context' => ['remote_operation_id' => 'stale-operation'],
    ]);
    $failedPinterest = PostPlatform::factory()->pinterest()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->pinterest()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);
    $disabledFailedPlatform = PostPlatform::factory()->tiktok()->failed()->disabled()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->tiktok()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsConfirmation('Queue publish attempts for these failed platforms?', 'yes')
        ->expectsOutput('2 publish attempt(s) queued.')
        ->assertSuccessful();

    expect($this->post->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($failedThreads->fresh()->status)->toBe(PlatformStatus::Pending)
        ->and($failedThreads->fresh()->platform_post_id)->toBeNull()
        ->and($failedThreads->fresh()->platform_url)->toBeNull()
        ->and($failedThreads->fresh()->published_at)->toBeNull()
        ->and($failedThreads->fresh()->error_message)->toBeNull()
        ->and($failedThreads->fresh()->error_context)->toBeNull()
        ->and($failedPinterest->fresh()->status)->toBe(PlatformStatus::Pending)
        ->and($publishedPlatform->fresh()->status)->toBe(PlatformStatus::Published)
        ->and($disabledFailedPlatform->fresh()->status)->toBe(PlatformStatus::Failed);

    Bus::assertDispatchedTimes(PublishToSocialPlatform::class, 2);
    Bus::assertDispatched(
        PublishToSocialPlatform::class,
        fn (PublishToSocialPlatform $job): bool => $job->postPlatform->is($failedThreads) && $job->uniqueAttempt === 0,
    );
});

test('it resumes a TikTok publish_id instead of starting from scratch', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Storage::fake();

    $derivativePath = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($derivativePath, 'temporary image');
    $failedTikTok = PostPlatform::factory()->tiktok()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->tiktok()->create([
            'workspace_id' => $this->workspace->id,
        ]),
        'error_context' => [
            'tiktok_publish_id' => 'stale-publish-id',
            'tiktok_derivative_paths' => [$derivativePath],
            'retry_count' => 120,
            'max_retries' => 120,
            'category' => 'platform_unavailable',
        ],
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsConfirmation('Queue publish attempts for these failed platforms?', 'yes')
        ->assertSuccessful();

    Storage::assertExists($derivativePath);
    expect($failedTikTok->fresh()->status)->toBe(PlatformStatus::Pending)
        ->and($failedTikTok->fresh()->error_context)->toBe([
            'tiktok_publish_id' => 'stale-publish-id',
            'tiktok_derivative_paths' => [$derivativePath],
        ]);

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->postPlatform->is($failedTikTok));
});

test('it keeps an Instagram workflow checkpoint on retry', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $workflow = [
        'stage' => 'final_container',
        'container_id' => 'container-123',
    ];
    $failedInstagram = PostPlatform::factory()->instagram()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->instagram()->create([
            'workspace_id' => $this->workspace->id,
        ]),
        'error_context' => [
            'instagram_workflow' => $workflow,
            'retry_count' => 90,
            'max_retries' => 90,
            'category' => 'timeout',
        ],
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsConfirmation('Queue publish attempts for these failed platforms?', 'yes')
        ->assertSuccessful();

    expect($failedInstagram->fresh()->status)->toBe(PlatformStatus::Pending)
        ->and($failedInstagram->fresh()->error_context)->toBe([
            'instagram_workflow' => $workflow,
        ]);
});

test('it removes stale TikTok derivatives when there is no publish_id to resume', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Storage::fake();

    $derivativePath = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($derivativePath, 'temporary image');
    $failedTikTok = PostPlatform::factory()->tiktok()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->tiktok()->create([
            'workspace_id' => $this->workspace->id,
        ]),
        'error_context' => [
            'tiktok_derivative_paths' => [$derivativePath],
            'category' => 'unknown',
        ],
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsConfirmation('Queue publish attempts for these failed platforms?', 'yes')
        ->assertSuccessful();

    Storage::assertMissing($derivativePath);
    expect($failedTikTok->fresh()->status)->toBe(PlatformStatus::Pending)
        ->and($failedTikTok->fresh()->error_context)->toBeNull();

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->postPlatform->is($failedTikTok));
});

test('it does not change the post when confirmation is declined', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $failedPlatform = PostPlatform::factory()->threads()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->threads()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsConfirmation('Queue publish attempts for these failed platforms?', 'no')
        ->expectsOutput('Retry cancelled.')
        ->assertSuccessful();

    expect($this->post->fresh()->status)->toBe(PostStatus::PartiallyPublished)
        ->and($failedPlatform->fresh()->status)->toBe(PlatformStatus::Failed);

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('it rejects posts that are not in a terminal failure state', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    $this->post->update(['status' => PostStatus::Publishing]);

    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsOutput('Only failed or partially published posts can be retried.')
        ->assertFailed();

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('it retries a completely failed post', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    $this->post->update(['status' => PostStatus::Failed]);
    $failedPlatform = PostPlatform::factory()->threads()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->threads()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsConfirmation('Queue publish attempts for these failed platforms?', 'yes')
        ->assertSuccessful();

    expect($this->post->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($failedPlatform->fresh()->status)->toBe(PlatformStatus::Pending);

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->postPlatform->is($failedPlatform));
});

test('it fails when no failed enabled platform matches', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    PostPlatform::factory()->published()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsOutput('No failed enabled platforms matched this post.')
        ->assertFailed();

    expect($this->post->fresh()->status)->toBe(PostStatus::PartiallyPublished);
    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('it fails when the post does not exist', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $this->artisan('posts:retry', ['post' => '019ff9ae-068b-72bf-9f2e-0314ce7dc0e2'])
        ->expectsOutput('Post not found.')
        ->assertFailed();

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('a TikTok retry with a publish_id resumes instead of calling init', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/test-video.mp4',
            'url' => 'https://example.com/media/2026-01/test-video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'test-video.mp4',
        ]],
    ]);

    $failedTikTok = PostPlatform::factory()->tiktok()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->tiktok()->create([
            'workspace_id' => $this->workspace->id,
            'username' => 'tiktoker',
            'token_expires_at' => now()->addDay(),
        ]),
        'error_context' => [
            'tiktok_publish_id' => 'pub_existing',
            'retry_count' => 120,
            'max_retries' => 120,
        ],
    ]);

    Mail::fake();
    Queue::fake();

    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsConfirmation('Queue publish attempts for these failed platforms?', 'yes')
        ->assertSuccessful();

    $api = config('trypost.platforms.tiktok.api');
    Http::fake([
        $api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publicaly_available_post_id' => ['video_123'],
            ],
        ]),
    ]);

    (new PublishToSocialPlatform($failedTikTok->fresh()))->handle();

    expect($failedTikTok->fresh()->status)->toBe(PlatformStatus::Published)
        ->and($failedTikTok->fresh()->platform_post_id)->toBe('video_123');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/init/'));
});
