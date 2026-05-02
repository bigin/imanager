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
     * tokenizer settings or migration content changes.
     */
    public function rebuild(): void
    {
        try {
            $this->connection->exec('DELETE FROM items_fts');
            $this->connection->exec(
                'INSERT INTO items_fts(rowid, name, label, body) '
                    . 'SELECT i.id, IFNULL(i.name, \'\'), IFNULL(i.label, \'\'), '
                    . 'IFNULL(i.name, \'\') || \' \' || IFNULL(i.label, \'\') || \' \' || IFNULL(i.data, \'\') '
                    . 'FROM items i',
            );
        } catch (\PDOException $e) {
            throw StorageException::fromPdo($e, 'Full-text index rebuild failed');
        }
    }
}
