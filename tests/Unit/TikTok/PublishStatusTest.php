<?php

declare(strict_types=1);

use App\Enums\TikTok\PublishStatus;

test('publish status mirrors the tiktok status fetch values', function () {
    expect(PublishStatus::cases())->toHaveCount(5)
        ->and(PublishStatus::ProcessingUpload->value)->toBe('PROCESSING_UPLOAD')
        ->and(PublishStatus::ProcessingDownload->value)->toBe('PROCESSING_DOWNLOAD')
        ->and(PublishStatus::SendToUserInbox->value)->toBe('SEND_TO_USER_INBOX')
        ->and(PublishStatus::PublishComplete->value)->toBe('PUBLISH_COMPLETE')
        ->and(PublishStatus::Failed->value)->toBe('FAILED');
});

test('publish status tryFrom accepts known values and rejects unknown', function (string $value, ?PublishStatus $expected) {
    expect(PublishStatus::tryFrom($value))->toBe($expected);
})->with([
    ['PROCESSING_UPLOAD', PublishStatus::ProcessingUpload],
    ['PROCESSING_DOWNLOAD', PublishStatus::ProcessingDownload],
    ['SEND_TO_USER_INBOX', PublishStatus::SendToUserInbox],
    ['PUBLISH_COMPLETE', PublishStatus::PublishComplete],
    ['FAILED', PublishStatus::Failed],
    ['PUBLISH_FAILED', null],
    ['UNKNOWN', null],
    ['', null],
]);
