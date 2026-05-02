<?php

declare(strict_types=1);

namespace Imanager\Files;

/**
 * Validated, source-agnostic wrapper around a single uploaded file.
 *
 * Production code constructs via {@see fromPhpUpload()}, which does the
 * `is_uploaded_file()` check that PHP requires for genuine multipart
 * uploads. Tests and CLI flows use {@see fromPath()} to point at a local
 * file directly.
 *
 * Decoupling the value object from the `$_FILES` superglobal lets
 * {@see UploadHandler} stay testable without faking HTTP context.
 */
final readonly class UploadedFile
{
    public function __construct(
        public string $name,
        public string $declaredMime,
        public string $tmpPath,
        public int $size,
        public int $errorCode = \UPLOAD_ERR_OK,
    ) {}

    /**
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $entry
     */
    public static function fromPhpUpload(array $entry): self
    {
        $name = isset($entry['name']) && \is_string($entry['name']) ? $entry['name'] : '';
        $type = isset($entry['type']) && \is_string($entry['type']) ? $entry['type'] : 'application/octet-stream';
        $tmp = isset($entry['tmp_name']) && \is_string($entry['tmp_name']) ? $entry['tmp_name'] : '';
        $error = isset($entry['error']) && \is_int($entry['error']) ? $entry['error'] : \UPLOAD_ERR_NO_FILE;
        $size = isset($entry['size']) && \is_int($entry['size']) ? $entry['size'] : 0;

        if ($error === \UPLOAD_ERR_OK && $tmp !== '' && ! is_uploaded_file($tmp)) {
            throw new UploadException(\sprintf(
                'Uploaded file "%s" did not arrive via HTTP POST',
                $name,
            ));
        }

        return new self(name: $name, declaredMime: $type, tmpPath: $tmp, size: $size, errorCode: $error);
    }

    /**
     * Build an UploadedFile pointing at an existing local file. Used in
     * tests and for CLI imports — bypasses the `is_uploaded_file()` guard
     * because there's nothing HTTP-shaped about the input.
     */
    public static function fromPath(string $path, ?string $name = null): self
    {
        if (! is_file($path)) {
            throw new UploadException(\sprintf('Source file "%s" not found', $path));
        }
        $size = filesize($path);
        return new self(
            name: $name ?? basename($path),
            declaredMime: mime_content_type($path) ?: 'application/octet-stream',
            tmpPath: $path,
            size: $size === false ? 0 : $size,
        );
    }
}
