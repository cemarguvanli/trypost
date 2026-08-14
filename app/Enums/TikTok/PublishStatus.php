<?php

declare(strict_types=1);

namespace App\Enums\TikTok;

/**
 * TikTok `data.status` values from POST /v2/post/publish/status/fetch/.
 *
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-get-video-status
 */
enum PublishStatus: string
{
    case ProcessingUpload = 'PROCESSING_UPLOAD';
    case ProcessingDownload = 'PROCESSING_DOWNLOAD';
    case SendToUserInbox = 'SEND_TO_USER_INBOX';
    case PublishComplete = 'PUBLISH_COMPLETE';
    case Failed = 'FAILED';
}
