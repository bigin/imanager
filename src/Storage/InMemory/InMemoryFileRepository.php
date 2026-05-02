<?php

declare(strict_types=1);

namespace Imanager\Storage\InMemory;

use Imanager\Domain\File;
use Imanager\Storage\FileRepository;

final readonly class InMemoryFileRepository implements FileRepository
{
    public function __construct(private InMemoryStorage $storage) {}

    public function find(int $id): ?File
    {
        return $this->storage->getFile($id);
    }

    public function findByItem(int $itemId): array
    {
        return $this->storage->filesByItem($itemId);
    }

    public function findByItemAndField(int $itemId, int $fieldId): array
    {
        return $this->storage->filesByItemAndField($itemId, $fieldId);
    }

    public function save(File $file): File
    {
        return $this->storage->saveFile($file);
    }

    public function delete(int $id): void
    {
        $this->storage->deleteFile($id);
    }
}
