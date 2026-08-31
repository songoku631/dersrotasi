<?php

declare(strict_types=1);

namespace DersRotasi\AI;

use Throwable;

final class LocalImageModerationFallback implements ImageContentModerator
{
    public function __construct(private readonly ImageContentModerator $primary)
    {
    }

    public function inspectImage(string $mimeType, string $bytes): array
    {
        try {
            return $this->primary->inspectImage($mimeType, $bytes);
        } catch (Throwable) {
            error_log('[PROFILE_PHOTO] event=local_moderation_fallback');

            return ['flagged' => false, 'categories' => []];
        }
    }
}
