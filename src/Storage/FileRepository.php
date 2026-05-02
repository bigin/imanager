<?php

declare(strict_types=1);

namespace Imanager\Storage;

use Imanager\Domain\File;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;

interface FileRepository
{
    public function find(int $id): ?File;

    /**
     * @return list<File> ordered by `position` then `id`
     */
    public function findByItem(int $itemId): array;

    /**
     * @return list<File> ordered by `position` then `id`
     */
    public function findByItemAndField(int $itemId, int $fieldId): array;

    /**
     * Persist a {@see File}. id-less inputs get a fresh id; id-bearing inputs
     * upsert against that id (throwing {@see NotFoundException} if it doesn't
     * exist).
     *
     * @throws NotFoundException
     * @throws StorageException
     */
    public function save(File $file): File;

    /**
     * @throws NotFoundException
     * @throws StorageException
     */
    public function delete(int $id): void;
}
