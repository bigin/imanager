<?php

declare(strict_types=1);

namespace Imanager\Files;

use Imanager\Domain\File;
use Imanager\Storage\FileRepository;
use Imanager\Validation\Sanitizer;

/**
 * Validates an uploaded file, persists its bytes through {@see FileStorage},
 * extracts image dimensions when applicable, and records the metadata via
 * the supplied {@see FileRepository}.
 *
 * The handler is deliberately stateless and small. Concerns it does NOT
 * carry:
 *   - HTTP routing (Phase 14 wires an endpoint that calls into this)
 *   - Thumbnail generation (callers ask {@see ImageProcessor} themselves
 *     once they have the persisted File)
 *   - Cleanup-on-item-delete (Phase 14 listens for ItemDeleted events
 *     and walks file rows + storage paths)
 */
final readonly class UploadHandler
{
    public function __construct(
        private FileStorage $storage,
        private FileRepository $repository,
        private Sanitizer $sanitizer,
        private ?ImageProcessor $images = null,
    ) {}

    public function handle(
        UploadedFile $upload,
        int $itemId,
        int $fieldId,
        UploadConstraints $constraints,
        int $position = 0,
    ): File {
        $this->assertUploadOk($upload);
        $this->assertSizeOk($upload, $constraints);

        $extension = self::extension($upload->name);
        if (! $constraints->permitsExtension($extension)) {
            throw new UploadException(\sprintf(
                'Extension "%s" is not in the allow-list',
                $extension,
            ));
        }

        $sniffedMime = $this->sniffMime($upload->tmpPath, $upload->declaredMime);
        if (! $constraints->permitsMime($sniffedMime)) {
            throw new UploadException(\sprintf(
                'Mime "%s" is not in the allow-list',
                $sniffedMime,
            ));
        }

        $safeName = $this->sanitizer->filename($upload->name);
        if ($safeName === '') {
            throw new UploadException('Sanitised filename is empty');
        }
        $relativePath = $this->resolveCollisionFreePath($itemId, $fieldId, $safeName);

        $this->storage->put($relativePath, $upload->tmpPath);

        [$width, $height] = $this->detectDimensions($relativePath, $sniffedMime);

        $file = new File(
            id: null,
            itemId: $itemId,
            fieldId: $fieldId,
            name: $safeName,
            path: $relativePath,
            mime: $sniffedMime,
            size: $upload->size,
            width: $width,
            height: $height,
            position: $position,
            created: time(),
        );
        return $this->repository->save($file);
    }

    private function assertUploadOk(UploadedFile $upload): void
    {
        if ($upload->errorCode === \UPLOAD_ERR_OK) {
            return;
        }
        throw new UploadException(\sprintf(
            'Upload "%s" failed (code %d: %s)',
            $upload->name,
            $upload->errorCode,
            self::uploadErrorText($upload->errorCode),
        ));
    }

    private function assertSizeOk(UploadedFile $upload, UploadConstraints $constraints): void
    {
        if ($upload->size > $constraints->maxSizeBytes) {
            throw new UploadException(\sprintf(
                'Upload "%s" is %d bytes; max allowed is %d',
                $upload->name,
                $upload->size,
                $constraints->maxSizeBytes,
            ));
        }
    }

    private function sniffMime(string $path, string $fallback): string
    {
        $detected = @mime_content_type($path);
        if (\is_string($detected) && $detected !== '') {
            return $detected;
        }
        return $fallback;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function detectDimensions(string $relativePath, string $mime): array
    {
        $images = $this->images;
        if ($images === null) {
            return [0, 0];
        }
        if (! str_starts_with($mime, 'image/')) {
            return [0, 0];
        }
        try {
            $dimensions = $images->dimensions($this->storage->absolutePath($relativePath));
        } catch (ImageProcessingException) {
            return [0, 0];
        }
        return [$dimensions['width'], $dimensions['height']];
    }

    private function resolveCollisionFreePath(int $itemId, int $fieldId, string $name): string
    {
        $base = \sprintf('%d/%d/%s', $itemId, $fieldId, $name);
        if (! $this->storage->exists($base)) {
            return $base;
        }
        // Append `-2`, `-3`, … before the extension until we find a free slot.
        $extension = self::extension($name);
        $stem = $extension === '' ? $name : substr($name, 0, -\strlen($extension) - 1);

        for ($n = 2; $n < 1000; $n++) {
            $suffixed = $extension === ''
                ? \sprintf('%s-%d', $stem, $n)
                : \sprintf('%s-%d.%s', $stem, $n, $extension);
            $candidate = \sprintf('%d/%d/%s', $itemId, $fieldId, $suffixed);
            if (! $this->storage->exists($candidate)) {
                return $candidate;
            }
        }

        throw new UploadException(\sprintf(
            'Cannot resolve a collision-free path for "%s" in item %d / field %d',
            $name,
            $itemId,
            $fieldId,
        ));
    }

    private static function extension(string $filename): string
    {
        $dot = strrpos($filename, '.');
        if ($dot === false || $dot === \strlen($filename) - 1) {
            return '';
        }
        return strtolower(substr($filename, $dot + 1));
    }

    private static function uploadErrorText(int $code): string
    {
        return match ($code) {
            \UPLOAD_ERR_INI_SIZE => 'file exceeds upload_max_filesize',
            \UPLOAD_ERR_FORM_SIZE => 'file exceeds MAX_FILE_SIZE',
            \UPLOAD_ERR_PARTIAL => 'partial upload',
            \UPLOAD_ERR_NO_FILE => 'no file uploaded',
            \UPLOAD_ERR_NO_TMP_DIR => 'no tmp directory',
            \UPLOAD_ERR_CANT_WRITE => 'cannot write to disk',
            \UPLOAD_ERR_EXTENSION => 'upload stopped by extension',
            default => 'unknown error',
        };
    }
}
