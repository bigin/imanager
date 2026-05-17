<?php

declare(strict_types=1);

namespace Imanager\Storage\Sqlite;

use Imanager\Domain\Event\ItemCreated;
use Imanager\Domain\Event\ItemDeleted;
use Imanager\Domain\Event\ItemUpdated;
use Imanager\Domain\Item;
use Imanager\Events\NullEventDispatcher;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Query\Clause;
use Imanager\Query\Direction;
use Imanager\Query\Query;
use Imanager\Search\FtsBody;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\ItemRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class SqliteItemRepository implements ItemRepository
{
    /**
     * Structural columns on the `items` table. Anything outside this set is
     * resolved through `json_extract(data, '$.<field>')`.
     *
     * @var array<string, string>
     */
    private const STRUCTURAL_COLUMNS = [
        'id' => 'id',
        'category_id' => 'category_id',
        'categoryId' => 'category_id',
        'name' => 'name',
        'label' => 'label',
        'position' => 'position',
        'active' => 'active',
        'created' => 'created',
        'updated' => 'updated',
    ];

    private readonly EventDispatcherInterface $events;

    /**
     * Optional repository used to look up the per-field `searchable`
     * flag. When `null` (the 2.0/2.1 constructor signature), syncFts
     * indexes every value — preserving legacy behavior for direct
     * callers, with a one-time deprecation notice on first FTS write.
     */
    private readonly ?FieldRepository $fields;

    public function __construct(
        private \PDO $connection,
        ?EventDispatcherInterface $events = null,
        ?FieldRepository $fields = null,
    ) {
        $this->events = $events ?? new NullEventDispatcher();
        $this->fields = $fields;
    }

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
        $dataJson = self::encodeData($item->data->toArray());

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

            $newId = (int) $this->connection->lastInsertId();
            $this->syncFts($newId, $item->categoryId, $item->name, $item->label, $item->data->toArray());

            $created_item = new Item(
                id: $newId,
                categoryId: $item->categoryId,
                name: $item->name,
                label: $item->label,
                position: $item->position,
                active: $item->active,
                data: $item->data,
                created: $created,
                updated: $now,
            );
            $this->events->dispatch(new ItemCreated($created_item, $now));
            return $created_item;
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

        $this->syncFts($item->id, $item->categoryId, $item->name, $item->label, $item->data->toArray());

        $updated = new Item(
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
        $this->events->dispatch(new ItemUpdated($existing, $updated, $now));
        return $updated;
    }

    public function delete(int $id): void
    {
        // Read the row first so the event we fire below carries category
        // context, and so a "row not found" surfaces as a NotFoundException
        // rather than as a no-op delete.
        $existing = $this->find($id);
        if ($existing === null) {
            throw NotFoundException::item(0, $id);
        }

        // Fire the deletion event *before* the SQL DELETE runs. The
        // `files` FK cascades on `ON DELETE CASCADE`, so listeners that
        // need to inspect related state (file cleanup, cache keys for
        // active uploads) wouldn't see anything once the row is gone.
        $this->events->dispatch(new ItemDeleted($id, $existing->categoryId, time()));

        $stmt = $this->connection->prepare('DELETE FROM items WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $ftsDelete = $this->connection->prepare('DELETE FROM items_fts WHERE rowid = :id');
        $ftsDelete->execute([':id' => $id]);
    }

    /**
     * Insert-or-replace the FTS index row for `$id`. When a
     * `FieldRepository` was wired into this repository, only the fields
     * whose `searchable` flag is true are written to the body; otherwise
     * (the legacy 2.0/2.1 constructor signature) every dynamic value goes
     * in and a one-time deprecation notice fires.
     *
     * @param array<string, mixed> $data
     */
    private function syncFts(int $id, int $categoryId, ?string $name, ?string $label, array $data): void
    {
        $body = FtsBody::compose($name, $label, $data, $this->searchableKeysFor($categoryId));

        $delete = $this->connection->prepare('DELETE FROM items_fts WHERE rowid = :id');
        $delete->execute([':id' => $id]);

        $insert = $this->connection->prepare(
            'INSERT INTO items_fts (rowid, name, label, body) VALUES (:id, :name, :label, :body)',
        );
        $insert->execute([
            ':id' => $id,
            ':name' => $name ?? '',
            ':label' => $label ?? '',
            ':body' => $body,
        ]);
    }

    /**
     * Return the list of field names whose `searchable` flag is true for
     * `$categoryId`, or `null` when no `FieldRepository` was wired (legacy
     * 2.0/2.1 signature — fall back to "index everything"). The first such
     * fall-through emits an `E_USER_DEPRECATED` notice once per process so
     * external integrators get a heads-up without breaking.
     *
     * Each call re-queries the fields table. The query is local SQLite
     * (sub-millisecond for the dozens of fields per category iManager
     * realistically targets), and skipping the cache avoids staleness in
     * long-running CLI processes that mutate the schema mid-run.
     *
     * @return list<string>|null
     */
    private function searchableKeysFor(int $categoryId): ?array
    {
        if ($this->fields === null) {
            static $warned = false;
            if (! $warned) {
                $warned = true;
                @trigger_error(
                    'SqliteItemRepository was constructed without a FieldRepository — '
                    . 'FTS will index every field value (legacy 2.0/2.1 behavior). Pass '
                    . 'the FieldRepository into the third constructor argument to honor '
                    . 'per-field searchable flags. The no-arg form will become an error '
                    . 'in 3.0.',
                    \E_USER_DEPRECATED,
                );
            }
            return null;
        }

        $keys = [];
        foreach ($this->fields->findByCategory($categoryId) as $field) {
            if ($field->searchable) {
                $keys[] = $field->name;
            }
        }
        return $keys;
    }

    public function query(Query $query): array
    {
        [$where, $params] = $this->buildWhere($query);
        $orderBy = $this->buildOrderBy($query);

        $sql = 'SELECT * FROM items' . $where . $orderBy;

        if ($query->limit > 0) {
            $sql .= ' LIMIT :__limit OFFSET :__offset';
            $params[':__limit'] = $query->limit;
            $params[':__offset'] = $query->offset;
        } elseif ($query->offset > 0) {
            $sql .= ' LIMIT -1 OFFSET :__offset';
            $params[':__offset'] = $query->offset;
        }

        $stmt = $this->connection->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    public function count(Query $query): int
    {
        [$where, $params] = $this->buildWhere($query);

        $stmt = $this->connection->prepare('SELECT COUNT(*) FROM items' . $where);
        $this->bindAll($stmt, $params);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(Query $query): array
    {
        $fragments = [];
        $params = [];
        $idx = 0;

        if ($query->categoryId !== null) {
            $fragments[] = 'category_id = :__cat';
            $params[':__cat'] = $query->categoryId;
        }

        foreach ($query->where as $clause) {
            $placeholder = ':v' . $idx++;
            $fragments[] = self::clauseSql($clause, $placeholder);
            $params[$placeholder] = self::clauseValue($clause);
        }

        if ($fragments === []) {
            return ['', $params];
        }
        return [' WHERE ' . implode(' AND ', $fragments), $params];
    }

    private function buildOrderBy(Query $query): string
    {
        if ($query->orderBy === []) {
            return ' ORDER BY position, id';
        }
        $parts = [];
        foreach ($query->orderBy as $order) {
            $parts[] = self::columnExpression($order->field)
                . ($order->direction === Direction::Desc ? ' DESC' : ' ASC');
        }
        return ' ORDER BY ' . implode(', ', $parts);
    }

    private static function clauseSql(Clause $clause, string $placeholder): string
    {
        return self::columnExpression($clause->field)
            . ' ' . $clause->op->value
            . ' ' . $placeholder;
    }

    /**
     * Resolve a query field name to either a structural column or a
     * `json_extract(data, '$.<field>')` expression. JSON-extracted values are
     * `NULL` when the key is missing, which matches the InMemory backend.
     */
    private static function columnExpression(string $field): string
    {
        if (isset(self::STRUCTURAL_COLUMNS[$field])) {
            return self::STRUCTURAL_COLUMNS[$field];
        }
        // The field name is sanitized by the upstream FieldType pipeline
        // (Phase 7); single-quote escaping here is defense-in-depth against
        // any caller that bypasses it.
        $escaped = str_replace("'", "''", $field);
        return "json_extract(data, '$." . $escaped . "')";
    }

    private static function clauseValue(Clause $clause): mixed
    {
        $v = $clause->value;
        if (\is_bool($v)) {
            return $v ? 1 : 0;
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindAll(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if (\is_int($value)) {
                $stmt->bindValue($key, $value, \PDO::PARAM_INT);
            } elseif (\is_bool($value)) {
                $stmt->bindValue($key, $value, \PDO::PARAM_BOOL);
            } elseif ($value === null) {
                $stmt->bindValue($key, null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($key, (string) $value, \PDO::PARAM_STR);
            }
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
