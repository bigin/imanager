<?php

declare(strict_types=1);

namespace Imanager\Storage\Sqlite;

use Imanager\Domain\File;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Storage\FileRepository;

final readonly class SqliteFileRepository implements FileRepository
{
    public function __construct(private \PDO $connection) {}

    public function find(int $id): ?File
    {
        $stmt = $this->connection->prepare('SELECT * FROM files WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    public function findByItem(int $itemId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT * FROM files WHERE item_id = :iid ORDER BY position, id',
        );
        $stmt->execute([':iid' => $itemId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    public function findByItemAndField(int $itemId, int $fieldId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT * FROM files WHERE item_id = :iid AND field_id = :fid ORDER BY position, id',
        );
        $stmt->execute([':iid' => $itemId, ':fid' => $fieldId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    public function save(File $file): File
    {
        $now = time();
        $created = $file->created !== 0 ? $file->created : $now;

        if ($file->id === null) {
            try {
                $stmt = $this->connection->prepare(
                    'INSERT INTO files (
                        item_id, field_id, name, path, mime, size,
                        width, height, position, created, title
                     ) VALUES (
                        :iid, :fid, :name, :path, :mime, :size,
                        :w, :h, :pos, :created, :title
                     )',
                );
                $stmt->execute([
                    ':iid' => $file->itemId,
                    ':fid' => $file->fieldId,
                    ':name' => $file->name,
                    ':path' => $file->path,
                    ':mime' => $file->mime,
                    ':size' => $file->size,
                    ':w' => $file->width,
                    ':h' => $file->height,
                    ':pos' => $file->position,
                    ':created' => $created,
                    ':title' => $file->title,
                ]);
            } catch (\PDOException $e) {
                throw self::translatePdoException($e);
            }

            return new File(
                id: (int) $this->connection->lastInsertId(),
                itemId: $file->itemId,
                fieldId: $file->fieldId,
                name: $file->name,
                path: $file->path,
                mime: $file->mime,
                size: $file->size,
                width: $file->width,
                height: $file->height,
                position: $file->position,
                created: $created,
                title: $file->title,
            );
        }

        $existing = $this->find($file->id);
        if ($existing === null) {
            throw NotFoundException::item(0, $file->id);
        }

        try {
            $stmt = $this->connection->prepare(
                'UPDATE files SET
                    item_id = :iid, field_id = :fid, name = :name, path = :path,
                    mime = :mime, size = :size, width = :w, height = :h,
                    position = :pos, title = :title
                 WHERE id = :id',
            );
            $stmt->execute([
                ':iid' => $file->itemId,
                ':fid' => $file->fieldId,
                ':name' => $file->name,
                ':path' => $file->path,
                ':mime' => $file->mime,
                ':size' => $file->size,
                ':w' => $file->width,
                ':h' => $file->height,
                ':pos' => $file->position,
                ':title' => $file->title,
                ':id' => $file->id,
            ]);
        } catch (\PDOException $e) {
            throw self::translatePdoException($e);
        }

        return new File(
            id: $file->id,
            itemId: $file->itemId,
            fieldId: $file->fieldId,
            name: $file->name,
            path: $file->path,
            mime: $file->mime,
            size: $file->size,
            width: $file->width,
            height: $file->height,
            position: $file->position,
            created: $existing->created,
            title: $file->title,
        );
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->prepare('DELETE FROM files WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw NotFoundException::item(0, $id);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): File
    {
        return new File(
            id: (int) $row['id'],
            itemId: (int) $row['item_id'],
            fieldId: (int) $row['field_id'],
            name: (string) $row['name'],
            path: (string) $row['path'],
            mime: (string) $row['mime'],
            size: (int) $row['size'],
            width: (int) $row['width'],
            height: (int) $row['height'],
            position: (int) $row['position'],
            created: (int) $row['created'],
            title: (string) ($row['title'] ?? ''),
        );
    }

    private static function translatePdoException(\PDOException $e): \Throwable
    {
        if (str_contains($e->getMessage(), 'FOREIGN KEY constraint failed')) {
            return new StorageException(
                'Cannot save file: referenced item or field does not exist',
                0,
                $e,
            );
        }
        return StorageException::fromPdo($e, 'Failed to save file');
    }
}
