<?php

declare(strict_types=1);

namespace Imanager\Domain;

/**
 * One uploaded file belonging to an item / field.
 *
 * Holds metadata only — the actual bytes live in the
 * {@see \Imanager\Files\FileStorage} backend. `path` is a relative path
 * (storage-root-relative) so the storage backend can be swapped (local
 * disk → S3 → CDN) without rewriting domain code.
 *
 * `width` / `height` are zero for non-images; `0, 0` is the explicit
 * "unknown / not applicable" marker.
 */
final readonly class File
{
    public function __construct(
        public ?int $id,
        public int $itemId,
        public int $fieldId,
        public string $name,
        public string $path,
        public string $mime,
        public int $size,
        public int $width = 0,
        public int $height = 0,
        public int $position = 0,
        public int $created = 0,
        public string $title = '',
    ) {
        if ($id !== null && $id < 1) {
            throw new \InvalidArgumentException('File id, when set, must be >= 1');
        }
        if ($itemId < 1) {
            throw new \InvalidArgumentException('File itemId must be >= 1');
        }
        if ($fieldId < 1) {
            throw new \InvalidArgumentException('File fieldId must be >= 1');
        }
        if (trim($name) === '') {
            throw new \InvalidArgumentException('File name must not be empty');
        }
        if (trim($path) === '') {
            throw new \InvalidArgumentException('File path must not be empty');
        }
        if ($size < 0) {
            throw new \InvalidArgumentException('File size must be >= 0');
        }
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('File dimensions must be >= 0');
        }
        if ($position < 0 || $created < 0) {
            throw new \InvalidArgumentException('File position / created must be >= 0');
        }
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            itemId: $this->itemId,
            fieldId: $this->fieldId,
            name: $this->name,
            path: $this->path,
            mime: $this->mime,
            size: $this->size,
            width: $this->width,
            height: $this->height,
            position: $this->position,
            created: $this->created,
            title: $this->title,
        );
    }

    public function withTitle(string $title): self
    {
        return new self(
            id: $this->id,
            itemId: $this->itemId,
            fieldId: $this->fieldId,
            name: $this->name,
            path: $this->path,
            mime: $this->mime,
            size: $this->size,
            width: $this->width,
            height: $this->height,
            position: $this->position,
            created: $this->created,
            title: $title,
        );
    }

    public function withPosition(int $position): self
    {
        return new self(
            id: $this->id,
            itemId: $this->itemId,
            fieldId: $this->fieldId,
            name: $this->name,
            path: $this->path,
            mime: $this->mime,
            size: $this->size,
            width: $this->width,
            height: $this->height,
            position: $position,
            created: $this->created,
            title: $this->title,
        );
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
