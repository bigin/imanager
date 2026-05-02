<?php

declare(strict_types=1);

namespace Imanager\Files;

/**
 * Per-field upload policy: max size, allowed extensions, allowed mime types.
 *
 * Empty allow-lists are treated as "any" — pass the lists explicitly for
 * lock-down, omit them for permissive defaults. The policy is enforced by
 * {@see UploadHandler::handle()} before the file ever lands in storage.
 */
final readonly class UploadConstraints
{
    /**
     * @param list<string> $allowedExtensions e.g. ['jpg', 'jpeg', 'png']
     * @param list<string> $allowedMimes      e.g. ['image/jpeg', 'image/png']
     */
    public function __construct(
        public int $maxSizeBytes = 10 * 1024 * 1024,
        public array $allowedExtensions = [],
        public array $allowedMimes = [],
    ) {
        if ($maxSizeBytes < 1) {
            throw new \InvalidArgumentException('maxSizeBytes must be >= 1');
        }
    }

    public function permitsExtension(string $extension): bool
    {
        if ($this->allowedExtensions === []) {
            return true;
        }
        return \in_array(strtolower($extension), array_map('strtolower', $this->allowedExtensions), true);
    }

    public function permitsMime(string $mime): bool
    {
        if ($this->allowedMimes === []) {
            return true;
        }
        return \in_array(strtolower($mime), array_map('strtolower', $this->allowedMimes), true);
    }

    /**
     * Convenience constructor for the common image case.
     */
    public static function images(int $maxSizeBytes = 8 * 1024 * 1024): self
    {
        return new self(
            maxSizeBytes: $maxSizeBytes,
            allowedExtensions: ['gif', 'jpg', 'jpeg', 'png', 'webp'],
            allowedMimes: ['image/gif', 'image/jpeg', 'image/png', 'image/webp'],
        );
    }
}
