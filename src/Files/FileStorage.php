<?php

declare(strict_types=1);

namespace Imanager\Files;

/**
 * Abstract file-bytes backend.
 *
 * Decouples uploaded-file storage from the rest of the system so the
 * default {@see LocalFileStorage} can later be swapped for an S3-style
 * driver without touching {@see UploadHandler} or the field-type plugins.
 *
 * Paths passed in are always **relative** to the backend's notion of a
 * storage root — `12/34/photo.jpg`, not `/var/www/uploads/12/34/photo.jpg`.
 * The backend resolves to absolute paths or remote URLs internally.
 */
interface FileStorage
{
    /**
     * Move (or copy when across filesystems) a source file into the storage
     * at `$relativePath`. Returns the resolved storage path the writer
     * just landed at.
     *
     * @throws FileStorageException on I/O failure or if the target already exists
     */
    public function put(string $relativePath, string $sourcePath): string;

    /**
     * Atomically write `$bytes` to `$relativePath`. Used for synthesised
     * artefacts (thumbnails) where the source is in memory rather than on
     * disk.
     *
     * @throws FileStorageException on I/O failure
     */
    public function write(string $relativePath, string $bytes): string;

    public function exists(string $relativePath): bool;

    /**
     * @throws FileStorageException
     */
    public function delete(string $relativePath): void;

    public function read(string $relativePath): string;

    /**
     * Public URL through which a browser can fetch the file. For local
     * storage this is typically `<publicUrlBase>/<relativePath>`; for S3
     * it would be the object URL.
     */
    public function url(string $relativePath): string;

    /**
     * Resolve to an absolute on-host path. May not be a real filesystem
     * path under non-local backends — callers that need actual bytes
     * should use {@see read()} instead.
     */
    public function absolutePath(string $relativePath): string;
}
