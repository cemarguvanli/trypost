<?php

declare(strict_types=1);

namespace App\Enums\Instagram;

/**
 * IG Container `status_code` values from GET /{IG_CONTAINER_ID}.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-container/
 */
enum ContainerStatus: string
{
    case Expired = 'EXPIRED';
    case Error = 'ERROR';
    case Finished = 'FINISHED';
    case InProgress = 'IN_PROGRESS';
    case Published = 'PUBLISHED';
}
