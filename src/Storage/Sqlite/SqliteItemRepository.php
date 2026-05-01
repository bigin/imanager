<?php

declare(strict_types=1);

namespace Imanager\Storage\Sqlite;

use Imanager\Domain\Item;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Storage\ItemRepository;

final readonly class SqliteItemRepository implements ItemRepository
{
    public function __construct(private \PDO $connection) {}

    public function find(int $id): ?Item
    {
        $stmt = $this->connection->prepare('SELECT * FROM items WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    public function findByCategory(int $categoryId, int $offset = 0, int $limit = 0): array
    {
        $sql = 'SELECT * FROM items WHERE category_id = :cid ORDER BY position, id';
        if ($limit > 0) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        } elseif ($offset > 0) {
            // SQLite requires LIMIT in front of OFFSET; -1 means "no limit".
            $sql .= ' LIMIT -1 OFFSET :offset';
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':cid', $categoryId, \PDO::PARAM_INT);
        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        }
        if ($offset > 0 || $limit > 0) {
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        }
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    public function countByCategory(int $categoryId): int
    {
        $stmt = $this->connection->prepare('SELECT COUNT(*) FROM items WHERE category_id = :cid');
        $stmt->execute([':cid' => $categoryId]);
        return (int) $stmt->fetchColumn();
    }

    public function save(Item $item): Item
    {
        $now = time();
        $created = $item->created !== 0 ? $item->created : $now;
        $dataJson = self::encodeData($item->data);

        if ($item->id === null) {
            try {
                $stmt = $this->connection->prepare(
                    'INSERT INTO items (
                        category_id, name, label, position, active, data, created, updated
                     ) VALUES (
                        :cid, :name, :label, :pos, :active, :data, :created, :updated
                     )',
                );
                $stmt->execute([
                    ':cid' => $item->categoryId,
                    ':name' => $item->name,
                    ':label' => $item->label,
                    ':pos' => $item->position,
                    ':active' => $item->active ? 1 : 0,
                    ':data' => $dataJson,
                    ':created' => $created,
                    ':updated' => $now,
                ]);
            } catch (\PDOException $e) {
                throw self::translatePdoException($e);
            }

            return new Item(
                id: (int) $this->connection->lastInsertId(),
                categoryId: $item->categoryId,
                name: $item->name,
                label: $item->label,
                position: $item->position,
                active: $item->active,
                data: $item->data,
                created: $created,
                updated: $now,
            );
        }

        $existing = $this->find($item->id);
        if ($existing === null) {
            throw NotFoundException::item($item->categoryId, $item->id);
        }

        try {
            $stmt = $this->connection->prepare(
                'UPDATE items SET
                    category_id = :cid, name = :name, label = :label, position = :pos,
                    active = :active, data = :data, updated = :updated
                  WHERE id = :id',
            );
            $stmt->execute([
                ':cid' => $item->categoryId,
                ':name' => $item->name,
                ':label' => $item->label,
                ':pos' => $item->position,
                ':active' => $item->active ? 1 : 0,
                ':data' => $dataJson,
                ':updated' => $now,
                ':id' => $item->id,
            ]);
        } catch (\PDOException $e) {
            throw self::translatePdoException($e);
        }

        return new Item(
            id: $item->id,
            categoryId: $item->categoryId,
            name: $item->name,
            label: $item->label,
            position: $item->position,
            active: $item->active,
            data: $item->data,
            created: $existing->created,
            updated: $now,
        );
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->prepare('DELETE FROM items WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw NotFoundException::item(0, $id);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encodeData(array $data): string
    {
        if ($data === []) {
            return '{}';
        }
        try {
            return json_encode($data, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StorageException('Item data is not JSON-serializable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Item
    {
        $dataRaw = (string) $row['data'];
        try {
            $decoded = json_decode($dataRaw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StorageException('Cannot decode item data: ' . $e->getMessage(), 0, $e);
        }
        if (! \is_array($decoded)) {
            $decoded = [];
        }
        /** @var array<string, mixed> $data */
        $data = $decoded;

        return new Item(
            id: (int) $row['id'],
            categoryId: (int) $row['category_id'],
            name: $row['name'] === null ? null : (string) $row['name'],
            label: $row['label'] === null ? null : (string) $row['label'],
            position: (int) $row['position'],
            active: (bool) $row['active'],
            data: $data,
            created: (int) $row['created'],
            updated: (int) $row['updated'],
        );
    }

    private static function translatePdoException(\PDOException $e): \Throwable
    {
        if (str_contains($e->getMessage(), 'FOREIGN KEY constraint failed')) {
            return new StorageException(
                'Cannot save item: referenced category does not exist',
                0,
                $e,
            );
        }
        return StorageException::fromPdo($e, 'Failed to save item');
    }
}
