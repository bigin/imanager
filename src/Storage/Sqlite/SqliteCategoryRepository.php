<?php

declare(strict_types=1);

namespace Imanager\Storage\Sqlite;

use Imanager\Domain\Category;
use Imanager\Domain\Event\CategoryCreated;
use Imanager\Domain\Event\CategoryDeleted;
use Imanager\Domain\Event\CategoryUpdated;
use Imanager\Enum\InputErrorCode;
use Imanager\Events\NullEventDispatcher;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Exception\ValidationException;
use Imanager\Storage\CategoryRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

final class SqliteCategoryRepository implements CategoryRepository
{
    private readonly EventDispatcherInterface $events;

    public function __construct(
        private readonly \PDO $connection,
        ?EventDispatcherInterface $events = null,
    ) {
        $this->events = $events ?? new NullEventDispatcher();
    }

    public function find(int $id): ?Category
    {
        $stmt = $this->connection->prepare('SELECT * FROM categories WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    public function findBySlug(string $slug): ?Category
    {
        $stmt = $this->connection->prepare('SELECT * FROM categories WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    public function findAll(): array
    {
        $stmt = $this->connection->query('SELECT * FROM categories ORDER BY position, id');
        if ($stmt === false) {
            throw new StorageException('Failed to list categories');
        }
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    public function save(Category $category): Category
    {
        $now = time();
        $created = $category->created !== 0 ? $category->created : $now;

        if ($category->id === null) {
            try {
                $stmt = $this->connection->prepare(
                    'INSERT INTO categories (name, slug, position, created, updated)
                     VALUES (:name, :slug, :pos, :created, :updated)',
                );
                $stmt->execute([
                    ':name' => $category->name,
                    ':slug' => $category->slug,
                    ':pos' => $category->position,
                    ':created' => $created,
                    ':updated' => $now,
                ]);
            } catch (\PDOException $e) {
                throw self::translatePdoException($e);
            }

            $id = (int) $this->connection->lastInsertId();
            $createdCat = new Category(
                id: $id,
                name: $category->name,
                slug: $category->slug,
                position: $category->position,
                created: $created,
                updated: $now,
            );
            $this->events->dispatch(new CategoryCreated($createdCat, $now));
            return $createdCat;
        }

        $existing = $this->find($category->id);
        if ($existing === null) {
            throw NotFoundException::category($category->id);
        }

        try {
            $stmt = $this->connection->prepare(
                'UPDATE categories
                    SET name = :name, slug = :slug, position = :pos, updated = :updated
                  WHERE id = :id',
            );
            $stmt->execute([
                ':name' => $category->name,
                ':slug' => $category->slug,
                ':pos' => $category->position,
                ':updated' => $now,
                ':id' => $category->id,
            ]);
        } catch (\PDOException $e) {
            throw self::translatePdoException($e);
        }

        $updated = new Category(
            id: $category->id,
            name: $category->name,
            slug: $category->slug,
            position: $category->position,
            created: $existing->created,
            updated: $now,
        );
        $this->events->dispatch(new CategoryUpdated($existing, $updated, $now));
        return $updated;
    }

    public function delete(int $id): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw NotFoundException::category($id);
        }
        // Fire before the FK cascade flattens fields/items so listeners
        // can still walk children if they need to.
        $this->events->dispatch(new CategoryDeleted($id, time()));

        $stmt = $this->connection->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute([':id' => $id]);
        // FK ON DELETE CASCADE removes fields and items.
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Category
    {
        return new Category(
            id: (int) $row['id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            position: (int) $row['position'],
            created: (int) $row['created'],
            updated: (int) $row['updated'],
        );
    }

    private static function translatePdoException(\PDOException $e): \Throwable
    {
        $message = $e->getMessage();
        if (str_contains($message, 'UNIQUE constraint failed')) {
            $field = self::violatedField($message);
            return new ValidationException(
                field: $field,
                errorCode: InputErrorCode::WrongValueFormat,
                message: \sprintf('Category %s already exists', $field !== '' ? $field : 'value'),
                previous: $e,
            );
        }
        return StorageException::fromPdo($e, 'Failed to save category');
    }

    private static function violatedField(string $message): string
    {
        if (preg_match('/UNIQUE constraint failed: \w+\.(\w+)/', $message, $m) === 1) {
            return $m[1];
        }
        return '';
    }
}
