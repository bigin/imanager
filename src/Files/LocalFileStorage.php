<?php

declare(strict_types=1);

namespace Imanager\Files;

/**
 * Local-disk implementation of {@see FileStorage}.
 *
 * Stores files under a `$rootPath` and serves them from `$publicUrlBase`.
 * Cross-filesystem moves are handled transparently — `put()` first tries
 * `rename()` and falls back to copy + unlink when the source and target
 * live on different mounts.
 */
final readonly class LocalFileStorage implements FileStorage
{
    public function __construct(
        private string $rootPath,
        private string $publicUrlBase = '/uploads',
    ) {
        if ($rootPath === '') {
            throw new \InvalidArgumentException('rootPath must not be empty');
        }
        if (! is_dir($rootPath) && ! @mkdir($rootPath, 0o755, true) && ! is_dir($rootPath)) {
            throw new FileStorageException(\sprintf(
                'Cannot create storage root "%s"',
                $rootPath,
            ));
        }
    }

    public function put(string $relativePath, string $sourcePath): string
    {
        $this->assertSafeRelativePath($relativePath);
        if (! is_file($sourcePath)) {
            throw new FileStorageException(\sprintf('Source file "%s" not found', $sourcePath));
        }

        $target = $this->absolutePath($relativePath);
        $this->ensureDirectory(\dirname($target));

        // Try fast move first; cross-filesystem moves fall back to copy + unlink.
        if (! @rename($sourcePath, $target)) {
            if (! @copy($sourcePath, $target)) {
                throw new FileStorageException(\sprintf(
                    'Cannot store "%s" at "%s"',
                    $sourcePath,
                    $target,
                ));
            }
            @unlink($sourcePath);
        }
        @chmod($target, 0o644);

        return $target;
    }

    public function write(string $relativePath, string $bytes): string
    {
        $this->assertSafeRelativePath($relativePath);
        $target = $this->absolutePath($relativePath);
        $this->ensureDirectory(\dirname($target));

        // Atomic: write to a sibling tmp file, then rename.
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new FileStorageException(\sprintf('Cannot write to "%s"', $tmp));
        }
        if (! @rename($tmp, $target)) {
            @unlink($tmp);
            throw new FileStorageException(\sprintf('Cannot finalize "%s"', $target));
        }
        @chmod($target, 0o644);
        return $target;
    }

    public function exists(string $relativePath): bool
    {
        $this->assertSafeRelativePath($relativePath);
        return is_file($this->absolutePath($relativePath));
    }

    public function delete(string $relativePath): void
    {
        $this->assertSafeRelativePath($relativePath);
        $target = $this->absolutePath($relativePath);
        if (! is_file($target)) {
            return;
        }
        if (! @unlink($target)) {
            throw new FileStorageException(\sprintf('Cannot delete "%s"', $target));
        }
    }

    public function read(string $relativePath): string
    {
        $this->assertSafeRelativePath($relativePath);
        $target = $this->absolutePath($relativePath);
        $bytes = @file_get_contents($target);
        if ($bytes === false) {
            throw new FileStorageException(\sprintf('Cannot read "%s"', $target));
        }
        return $bytes;
    }

    public function url(string $relativePath): string
    {
        $this->assertSafeRelativePath($relativePath);
        return rtrim($this->publicUrlBase, '/') . '/' . $relativePath;
    }

    public function absolutePath(string $relativePath): string
    {
        $this->assertSafeRelativePath($relativePath);
        return rtrim($this->rootPath, '/') . '/' . $relativePath;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new FileStorageException(\sprintf('Cannot create directory "%s"', $directory));
        }
    }

    /**
     * Defense-in-depth against path traversal via a relative path that
     * climbs out of the root with `..` segments. Callers should already
     * have sanitised the filename — this is the second line of defence.
     */
    private function assertSafeRelativePath(string $path): void
    {
        if ($path === '') {
            throw new FileStorageException('Relative path must not be empty');
        }
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            throw new FileStorageException(\sprintf(
                'Relative path "%s" must not be absolute',
                $path,
            ));
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new FileStorageException(\sprintf(
                    'Relative path "%s" must not contain ".." or "." segments',
                    $path,
                ));
            }
        }
    }
}
