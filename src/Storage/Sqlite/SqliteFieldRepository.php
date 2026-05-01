<?php

declare(strict_types=1);

namespace Imanager\Storage\Sqlite;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Exception\ValidationException;
use Imanager\Storage\FieldRepository;

final readonly class SqliteFieldRepository implements FieldRepository
{
    public function __construct(
        private \PDO $connection,
        private IndexedFields $indexedFields,
    ) {}

    public function find(int $id): ?Field
    {
        $stmt = $this->connection->prepare('SELECT * FROM fields WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    public function findByName(int $categoryId, string $name): ?Field
    {
        $stmt = $this->connection->prepare(
            'SELECT * FROM fields WHERE category_id = :cid AND name = :name',
        );
        $stmt->execute([':cid' => $categoryId, ':name' => $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    public function findByCategory(int $categoryId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT * FROM fields WHERE category_id = :cid ORDER BY position, id',
        );
        $stmt->execute([':cid' => $categoryId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    public function save(Field $field): Field
    {
        $now = time();
        $created = $field->created !== 0 ? $field->created : $now;
        $configJson = self::encodeConfig($field->config);

        if ($field->id === null) {
            try {
                $stmt = $this->connection->prepare(
                    'INSERT INTO fields (
                        category_id, name, label, type, position,
                        required, indexed, searchable, config, created, updated
                     ) VALUES (
                        :cid, :name, :label, :type, :pos,
                        :req, :idx, :search, :config, :created, :updated
                     )',
                );
                $stmt->execute([
                    ':cid' => $field->categoryId,
                    ':name' => $field->name,
                    ':label' => $field->label,
                    ':type' => $field->type->value,
                    ':pos' => $field->position,
                    ':req' => $field->required ? 1 : 0,
                    ':idx' => $field->indexed ? 1 : 0,
                    ':search' => $field->searchable ? 1 : 0,
                    ':config' => $configJson,
                    ':created' => $created,
                    ':updated' => $now,
                ]);
            } catch (\PDOException $e) {
                throw self::translatePdoException($e);
            }

            if ($field->indexed) {
                $this->indexedFields->create($field->categoryId, $field->name, $field->type);
            }

            return new Field(
                id: (int) $this->connection->lastInsertId(),
                categoryId: $field->categoryId,
                name: $field->name,
                label: $field->label,
                type: $field->type,
                position: $field->position,
                required: $field->required,
                indexed: $field->indexed,
                searchable: $field->searchable,
                config: $field->config,
                created: $created,
                updated: $now,
            );
        }

        $previous = $this->find($field->id);
        if ($previous === null) {
            throw NotFoundException::field($field->categoryId, $field->id);
        }

        try {
            $stmt = $this->connection->prepare(
                'UPDATE fields SET
                    name = :name, label = :label, type = :type, position = :pos,
                    required = :req, indexed = :idx, searchable = :search,
                    config = :config, updated = :updated
                  WHERE id = :id',
            );
            $stmt->execute([
                ':name' => $field->name,
                ':label' => $field->label,
                ':type' => $field->type->value,
                ':pos' => $field->position,
                ':req' => $field->required ? 1 : 0,
                ':idx' => $field->indexed ? 1 : 0,
                ':search' => $field->searchable ? 1 : 0,
                ':config' => $configJson,
                ':updated' => $now,
                ':id' => $field->id,
            ]);
        } catch (\PDOException $e) {
            throw self::translatePdoException($e);
        }

        $this->reconcileGeneratedColumn($previous, $field);

        return new Field(
            id: $field->id,
            categoryId: $field->categoryId,
            name: $field->name,
            label: $field->label,
            type: $field->type,
            position: $field->position,
            required: $field->required,
            indexed: $field->indexed,
            searchable: $field->searchable,
            config: $field->config,
            created: $previous->created,
            updated: $now,
        );
    }

    public function delete(int $id): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw NotFoundException::field(0, $id);
        }

        $stmt = $this->connection->prepare('DELETE FROM fields WHERE id = :id');
        $stmt->execute([':id' => $id]);

        if ($existing->indexed) {
            $this->indexedFields->drop($existing->categoryId, $existing->name);
        }
    }

    private function reconcileGeneratedColumn(Field $previous, Field $next): void
    {
        $renamed = $previous->name !== $next->name;
        $typeChanged = $previous->type !== $next->type;
        $indexedChanged = $previous->indexed !== $next->indexed;

        if (! $renamed && ! $typeChanged && ! $indexedChanged) {
            return;
        }

        if ($previous->indexed) {
            $this->indexedFields->drop($previous->categoryId, $previous->name);
        }
        if ($next->indexed) {
            $this->indexedFields->create($next->categoryId, $next->name, $next->type);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function encodeConfig(array $config): string
    {
        if ($config === []) {
            return '{}';
        }
        try {
            return json_encode($config, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StorageException('Field config is not JSON-serializable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Field
    {
        $configRaw = (string) $row['config'];
        try {
            $decoded = json_decode($configRaw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StorageException('Cannot decode field config: ' . $e->getMessage(), 0, $e);
        }
        if (! \is_array($decoded)) {
            $decoded = [];
        }
        /** @var array<string, mixed> $config */
        $config = $decoded;

        return new Field(
            id: (int) $row['id'],
            categoryId: (int) $row['category_id'],
            name: (string) $row['name'],
            label: $row['label'] === null ? null : (string) $row['label'],
            type: FieldType::from((string) $row['type']),
            position: (int) $row['position'],
            required: (bool) $row['required'],
            indexed: (bool) $row['indexed'],
            searchable: (bool) $row['searchable'],
            config: $config,
            created: (int) $row['created'],
            updated: (int) $row['updated'],
        );
    }

    private static function translatePdoException(\PDOException $e): \Throwable
    {
        $message = $e->getMessage();
        if (str_contains($message, 'UNIQUE constraint failed')) {
            return new ValidationException(
                field: 'name',
                errorCode: InputErrorCode::WrongValueFormat,
                message: 'Field name already exists in this category',
                previous: $e,
            );
        }
        if (str_contains($message, 'FOREIGN KEY constraint failed')) {
            return new StorageException(
                'Cannot save field: referenced category does not exist',
                0,
                $e,
            );
        }
        return StorageException::fromPdo($e, 'Failed to save field');
    }
}
