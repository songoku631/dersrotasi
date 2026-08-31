<?php

declare(strict_types=1);

use DersRotasi\AI\ImageContentModerator;
use DersRotasi\AI\LocalImageModerationFallback;

require dirname(__DIR__) . '/vendor/autoload.php';

function fallbackCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$unavailable = new class implements ImageContentModerator {
    public function inspectImage(string $mimeType, string $bytes): array
    {
        throw new RuntimeException('unavailable', 503);
    }
};
$fallbackResult = (new LocalImageModerationFallback($unavailable))->inspectImage('image/jpeg', 'bytes');
fallbackCheck(
    $fallbackResult === ['flagged' => false, 'categories' => []],
    'Unavailable moderation did not use the local fallback.'
);

$flagged = new class implements ImageContentModerator {
    public function inspectImage(string $mimeType, string $bytes): array
    {
        return ['flagged' => true, 'categories' => ['violence']];
    }
};
$flaggedResult = (new LocalImageModerationFallback($flagged))->inspectImage('image/jpeg', 'bytes');
fallbackCheck(
    $flaggedResult === ['flagged' => true, 'categories' => ['violence']],
    'Flagged moderation result was not preserved.'
);

echo "LocalImageModerationFallbackTest: OK\n";
