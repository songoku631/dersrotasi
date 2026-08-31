<?php

declare(strict_types=1);

namespace DersRotasi\Services;

use DersRotasi\AI\ImageContentModerator;
use RuntimeException;
use Throwable;

final class ProfilePhotoStorage
{
    public const REJECTION_MESSAGE = 'Bu görsel profil fotoğrafı olarak kullanılamıyor.';
    private const MAX_BYTES = 2_097_152;
    private const MAX_WIDTH = 4096;
    private const MAX_HEIGHT = 4096;
    private const MAX_PIXELS = 16_000_000;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly string $backendRoot,
        private readonly ImageContentModerator $moderator
    ) {
    }

    public function store(array $file, ?string $previousPath = null): string
    {
        $stagedPath = null;
        try {
            $sourcePath = $this->validateUpload($file);
            $imageInfo = @getimagesize($sourcePath);
            if (!is_array($imageInfo)) throw new RuntimeException(self::REJECTION_MESSAGE, 422);
            [$width, $height] = [(int) $imageInfo[0], (int) $imageInfo[1]];
            if ($width < 1 || $height < 1 || $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT || $width * $height > self::MAX_PIXELS) {
                throw new RuntimeException(self::REJECTION_MESSAGE, 422);
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath) ?: '';
            if (!in_array($mime, self::ALLOWED_MIMES, true) || ($imageInfo['mime'] ?? '') !== $mime) {
                throw new RuntimeException(self::REJECTION_MESSAGE, 422);
            }

            $stagedPath = $this->reencodeAsJpeg($sourcePath, $mime, $width, $height);
            $safeBytes = file_get_contents($stagedPath);
            if (!is_string($safeBytes) || $safeBytes === '') throw new RuntimeException(self::REJECTION_MESSAGE, 422);

            try {
                $moderation = $this->moderator->inspectImage('image/jpeg', $safeBytes);
            } catch (Throwable $exception) {
                error_log('[PROFILE_PHOTO] event=moderation_unavailable');
                throw new RuntimeException(self::REJECTION_MESSAGE, 503, $exception);
            }
            if (($moderation['flagged'] ?? true) === true) {
                error_log('[PROFILE_PHOTO] event=moderation_rejected');
                throw new RuntimeException(self::REJECTION_MESSAGE, 422);
            }

            $directory = $this->publicDirectory();
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException(self::REJECTION_MESSAGE, 500);
            }
            $name = bin2hex(random_bytes(24)) . '.jpg';
            $destination = $directory . DIRECTORY_SEPARATOR . $name;
            if (!rename($stagedPath, $destination)) throw new RuntimeException(self::REJECTION_MESSAGE, 500);
            $stagedPath = null;
            $this->deletePublished($previousPath);
            return '/uploads/profiles/' . $name;
        } finally {
            if ($stagedPath !== null && is_file($stagedPath)) @unlink($stagedPath);
        }
    }

    private function validateUpload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(self::REJECTION_MESSAGE, 422);
        $size = (int) ($file['size'] ?? 0);
        $path = (string) ($file['tmp_name'] ?? '');
        if ($size < 1 || $size > self::MAX_BYTES || !is_uploaded_file($path)) throw new RuntimeException(self::REJECTION_MESSAGE, 422);
        return $path;
    }

    private function reencodeAsJpeg(string $path, string $mime, int $width, int $height): string
    {
        $decoder = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            default => null,
        };
        if ($decoder === null || !function_exists($decoder)) throw new RuntimeException(self::REJECTION_MESSAGE, 503);
        $source = @$decoder($path);
        if (!$source instanceof \GdImage) throw new RuntimeException(self::REJECTION_MESSAGE, 422);

        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof \GdImage) { imagedestroy($source); throw new RuntimeException(self::REJECTION_MESSAGE, 500); }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        $stagingDirectory = rtrim($this->backendRoot, '/\\') . '/storage/profile-photo-staging';
        if (!is_dir($stagingDirectory) && !mkdir($stagingDirectory, 0750, true) && !is_dir($stagingDirectory)) {
            imagedestroy($source); imagedestroy($canvas); throw new RuntimeException(self::REJECTION_MESSAGE, 500);
        }
        $stagedPath = tempnam($stagingDirectory, 'photo-');
        $encoded = $stagedPath !== false && imagejpeg($canvas, $stagedPath, 88);
        imagedestroy($source); imagedestroy($canvas);
        if (!$encoded || $stagedPath === false) throw new RuntimeException(self::REJECTION_MESSAGE, 500);
        return $stagedPath;
    }

    private function publicDirectory(): string
    {
        return rtrim($this->backendRoot, '/\\') . '/public/uploads/profiles';
    }

    private function deletePublished(?string $path): void
    {
        if (!$path || !preg_match('#^/uploads/profiles/[a-f0-9]{48}\.jpg$#', $path)) return;
        $target = rtrim($this->backendRoot, '/\\') . '/public' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($target)) @unlink($target);
    }
}
