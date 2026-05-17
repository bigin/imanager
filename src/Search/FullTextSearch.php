<?php

declare(strict_types=1);

namespace Imanager\Search;

use Imanager\Exception\StorageException;

/**
 * SQLite-FTS5-backed full-text search over items.
 *
 * The FTS table (`items_fts`, declared in migration 0002) is kept in sync
 * by {@see \Imanager\Storage\Sqlite\SqliteItemRepository}: every item save
 * writes name + label + a flattened `data` blob into the index in the same
 * transaction; every delete removes the row.
 *
 * The search query language is whatever FTS5 accepts — bare words, `AND`/
 * `OR`/`NOT`, `"phrases"`, `prefix*` — see https://sqlite.org/fts5.html#the_match_operator.
 * Malformed queries surface as `StorageException` so the caller can fall
 * back to a simpler search path.
 */
final readonly class FullTextSearch
{
    public function __construct(private \PDO $connection) {}

    /**
     * @return list<SearchHit>
     */
    public function search(
        string $query,
        ?int $categoryId = null,
        int $limit = 20,
        int $offset = 0,
    ): array {
        $sql = 'SELECT items_fts.rowid AS id, i.category_id AS cid, '
            . 'snippet(items_fts, -1, \'<b>\', \'</b>\', \'…\', 16) AS snippet, '
            . 'rank '
            . 'FROM items_fts '
            . 'JOIN items i ON i.id = items_fts.rowid '
            . 'WHERE items_fts MATCH :q';
        $params = [':q' => $query];
        if ($categoryId !== null) {
            $sql .= ' AND i.category_id = :cid';
            $params[':cid'] = $categoryId;
        }
        $sql .= ' ORDER BY rank';

        if ($limit > 0) {
            $sql .= ' LIMIT :__limit OFFSET :__offset';
            $params[':__limit'] = $limit;
            $params[':__offset'] = $offset;
        } elseif ($offset > 0) {
            $sql .= ' LIMIT -1 OFFSET :__offset';
            $params[':__offset'] = $offset;
        }

        try {
            $stmt = $this->connection->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(
                    $key,
                    $value,
                    \is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR,
                );
            }
            $stmt->execute();
        } catch (\PDOException $e) {
            throw StorageException::fromPdo($e, 'Full-text search failed');
        }

        $hits = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $hits[] = new SearchHit(
                itemId: (int) $row['id'],
                categoryId: (int) $row['cid'],
                snippet: (string) $row['snippet'],
                rank: (float) $row['rank'],
            );
        }
        return $hits;
    }

    /**
     * Total number of items matching `$query`. `$limit` and `$offset` on
     * the corresponding {@see search()} call are ignored — this counts the
     * full result set, suitable for driving pagination.
     */
    public function count(string $query, ?int $categoryId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM items_fts JOIN items i ON i.id = items_fts.rowid '
            . 'WHERE items_fts MATCH :q';
        $params = [':q' => $query];
        if ($categoryId !== null) {
            $sql .= ' AND i.category_id = :cid';
            $params[':cid'] = $categoryId;
        }

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
        } catch (\PDOException $e) {
            throw StorageException::fromPdo($e, 'Full-text count failed');
        }
        return (int) $stmt->fetchColumn();
    }

    /**
     * Drop and rebuild the FTS index from scratch. Useful as a CLI op when
     * tokenizer settings or migration content changes, and the canonical
     * step after upgrading to 2.2.0 so the body column drops values whose
     * field's `searchable` flag is now false.
     *
     * The rebuild iterates items in PHP rather than running a single bulk
     * INSERT…SELECT because the per-category set of searchable field names
     * varies per row. This is a CLI op, not a hot path — per-row iteration
     * is acceptable at the install sizes iManager realistically targets.
     */
    public function rebuild(): void
    {
        try {
            // Per-category set of searchable field names. One query, used
            // for the entire rebuild.
            $allowedByCategory = [];
            $fieldsStmt = $this->connection->query(
                'SELECT category_id, name FROM fields WHERE searchable = 1',
            );
            if ($fieldsStmt !== false) {
                foreach ($fieldsStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $allowedByCategory[(int) $row['category_id']][] = (string) $row['name'];
                }
            }

            $this->connection->exec('DELETE FROM items_fts');

            $itemsStmt = $this->connection->query(
                'SELECT id, category_id, name, label, data FROM items',
            );
            if ($itemsStmt === false) {
                return;
            }

            $insert = $this->connection->prepare(
                'INSERT INTO items_fts (rowid, name, label, body) '
                . 'VALUES (:id, :name, :label, :body)',
            );

            foreach ($itemsStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $categoryId = (int) $row['category_id'];
                $allowed = $allowedByCategory[$categoryId] ?? [];

                $rawData = $row['data'] !== null ? (string) $row['data'] : '';
                $data = $rawData !== '' ? json_decode($rawData, true) : [];
                if (! \is_array($data)) {
                    $data = [];
                }

                $name = $row['name'] !== null ? (string) $row['name'] : '';
                $label = $row['label'] !== null ? (string) $row['label'] : '';

                $insert->execute([
                    ':id'    => (int) $row['id'],
                    ':name'  => $name,
                    ':label' => $label,
                    ':body'  => FtsBody::compose($name, $label, $data, $allowed),
                ]);
            }
        } catch (\PDOException $e) {
            throw StorageException::fromPdo($e, 'Full-text index rebuild failed');
        }
    }
}
