<?php

declare(strict_types=1);

namespace Imanager\Storage\InMemory;

use Imanager\Domain\Category;
use Imanager\Domain\Field;
use Imanager\Domain\Item;
use Imanager\Exception\NotFoundException;
use Imanager\Exception\StorageException;
use Imanager\Exception\ValidationException;
use Imanager\Storage\CategoryRepository;
use Imanager\Storage\FieldRepository;
use Imanager\Storage\ItemRepository;
use Imanager\Storage\Storage;

/**
 * In-process, state-only storage. Used by the contract test suites in this
 * repository, and by downstream tests that want a `Storage` they can wire
 * up without a database.
 *
 * Holds three id-keyed maps and a single shared id sequence per type. The
 * three repositories returned from {@see categories()}, {@see fields()} and
 * {@see items()} are thin facades over the methods on this class — all
 * mutation logic and cascade behavior lives here.
 */
final class InMemoryStorage implements Storage
{
    /** @var array<int, Category> */
    private array $categories = [];

    /** @var array<int, Field> */
    private array $fields = [];

    /** @var array<int, Item> */
    private array $items = [];

    /** @var array<int, \Imanager\Domain\File> */
    private array $files = [];

    private int $nextCategoryId = 1;
    private int $nextFieldId = 1;
    private int $nextItemId = 1;
    private int $nextFileId = 1;

    public function categories(): CategoryRepository
    {
        return new InMemoryCategoryRepository($this);
    }

    public function fields(): FieldRepository
    {
        return new InMemoryFieldRepository($this);
    }

    public function items(): ItemRepository
    {
        return new InMemoryItemRepository($this);
    }

    public function files(): \Imanager\Storage\FileRepository
    {
        return new InMemoryFileRepository($this);
    }

    public function transactional(callable $work): mixed
    {
        $snapshot = [
            'categories' => $this->categories,
            'fields' => $this->fields,
            'items' => $this->items,
            'files' => $this->files,
            'nextCategoryId' => $this->nextCategoryId,
            'nextFieldId' => $this->nextFieldId,
            'nextItemId' => $this->nextItemId,
            'nextFileId' => $this->nextFileId,
        ];

        try {
            return $work();
        } catch (\Throwable $e) {
            $this->categories = $snapshot['categories'];
            $this->fields = $snapshot['fields'];
            $this->items = $snapshot['items'];
            $this->files = $snapshot['files'];
            $this->nextCategoryId = $snapshot['nextCategoryId'];
            $this->nextFieldId = $snapshot['nextFieldId'];
            $this->nextItemId = $snapshot['nextItemId'];
            $this->nextFileId = $snapshot['nextFileId'];
            throw $e;
        }
    }

    // ──────────────────────────── Categories ─────────────────────────────

    public function getCategory(int $id): ?Category
    {
        return $this->categories[$id] ?? null;
    }

    public function findCategoryBySlug(string $slug): ?Category
    {
        foreach ($this->categories as $c) {
            if ($c->slug === $slug) {
                return $c;
            }
        }
        return null;
    }

    /**
     * @return list<Category>
     */
    public function allCategories(): array
    {
        return array_values($this->categories);
    }

    public function saveCategory(Category $category): Category
    {
        $now = time();

        if ($category->id === null) {
            $this->assertCategoryNameUnique($category->name, null);
            $this->assertCategorySlugUnique($category->slug, null);

            $id = $this->nextCategoryId++;
            $stored = new Category(
                id: $id,
                name: $category->name,
                slug: $category->slug,
                position: $category->position,
                created: $category->created !== 0 ? $category->created : $now,
                updated: $now,
            );
            $this->categories[$id] = $stored;
            return $stored;
        }

        if (! isset($this->categories[$category->id])) {
            throw NotFoundException::category($category->id);
        }

        $this->assertCategoryNameUnique($category->name, $category->id);
        $this->assertCategorySlugUnique($category->slug, $category->id);

        $id = $category->id;
        $previous = $this->categories[$id];
        $stored = new Category(
            id: $id,
            name: $category->name,
            slug: $category->slug,
            position: $category->position,
            created: $category->created !== 0 ? $category->created : $previous->created,
            updated: $now,
        );
        $this->categories[$id] = $stored;
        return $stored;
    }

    public function deleteCategory(int $id): void
    {
        if (! isset($this->categories[$id])) {
            throw NotFoundException::category($id);
        }
        unset($this->categories[$id]);

        // Build the doomed-item-id set so we can cascade files in lockstep.
        $orphanItemIds = [];
        foreach ($this->items as $itemId => $item) {
            if ($item->categoryId === $id) {
                $orphanItemIds[$itemId] = true;
            }
        }

        $keptFields = [];
        foreach ($this->fields as $fieldId => $field) {
            if ($field->categoryId !== $id) {
                $keptFields[$fieldId] = $field;
            }
        }
        $this->fields = $keptFields;

        $keptItems = [];
        foreach ($this->items as $itemId => $item) {
            if ($item->categoryId !== $id) {
                $keptItems[$itemId] = $item;
            }
        }
        $this->items = $keptItems;

        // Files cascade-die with their owning items.
        $keptFiles = [];
        foreach ($this->files as $fileId => $file) {
            if (! isset($orphanItemIds[$file->itemId])) {
                $keptFiles[$fileId] = $file;
            }
        }
        $this->files = $keptFiles;
    }

    private function assertCategoryNameUnique(string $name, ?int $exceptId): void
    {
        foreach ($this->categories as $c) {
            if ($c->name === $name && $c->id !== $exceptId) {
                throw new ValidationException(
                    field: 'name',
                    errorCode: \Imanager\Enum\InputErrorCode::WrongValueFormat,
                    message: \sprintf('Category name "%s" already exists', $name),
                );
            }
        }
    }

    private function assertCategorySlugUnique(string $slug, ?int $exceptId): void
    {
        foreach ($this->categories as $c) {
            if ($c->slug === $slug && $c->id !== $exceptId) {
                throw new ValidationException(
                    field: 'slug',
                    errorCode: \Imanager\Enum\InputErrorCode::WrongValueFormat,
                    message: \sprintf('Category slug "%s" already exists', $slug),
                );
            }
        }
    }

    // ────────────────────────────── Fields ───────────────────────────────

    public function getField(int $id): ?Field
    {
        return $this->fields[$id] ?? null;
    }

    public function findFieldByName(int $categoryId, string $name): ?Field
    {
        foreach ($this->fields as $f) {
            if ($f->categoryId === $categoryId && $f->name === $name) {
                return $f;
            }
        }
        return null;
    }

    /**
     * @return list<Field>
     */
    public function fieldsByCategory(int $categoryId): array
    {
        $out = [];
        foreach ($this->fields as $f) {
            if ($f->categoryId === $categoryId) {
                $out[] = $f;
            }
        }
        usort($out, static fn(Field $a, Field $b): int => $a->position <=> $b->position);
        return $out;
    }

    public function saveField(Field $field): Field
    {
        $now = time();

        if (! isset($this->categories[$field->categoryId])) {
            throw new StorageException(\sprintf(
                'Cannot save field "%s": category %d does not exist',
                $field->name,
                $field->categoryId,
            ));
        }

        if ($field->id === null) {
            $this->assertFieldNameUniqueInCategory($field->categoryId, $field->name, null);

            $id = $this->nextFieldId++;
            $stored = new Field(
                id: $id,
                categoryId: $field->categoryId,
                name: $field->name,
                label: $field->label,
                type: $field->type,
                position: $field->position,
                required: $field->required,
                indexed: $field->indexed,
                searchable: $field->searchable,
                config: $field->config,
                created: $field->created !== 0 ? $field->created : $now,
                updated: $now,
            );
            $this->fields[$id] = $stored;
            return $stored;
        }

        if (! isset($this->fields[$field->id])) {
            throw NotFoundException::field($field->categoryId, $field->id);
        }

        $this->assertFieldNameUniqueInCategory($field->categoryId, $field->name, $field->id);

        $id = $field->id;
        $previous = $this->fields[$id];
        $stored = new Field(
            id: $id,
            categoryId: $field->categoryId,
            name: $field->name,
            label: $field->label,
            type: $field->type,
            position: $field->position,
            required: $field->required,
            indexed: $field->indexed,
            searchable: $field->searchable,
            config: $field->config,
            created: $field->created !== 0 ? $field->created : $previous->created,
            updated: $now,
        );
        $this->fields[$id] = $stored;
        return $stored;
    }

    public function deleteField(int $id): void
    {
        if (! isset($this->fields[$id])) {
            throw NotFoundException::field(0, $id);
        }
        unset($this->fields[$id]);

        // Files referencing this field cascade away (FK ON DELETE CASCADE
        // in SQLite; in-memory we filter manually).
        $keptFiles = [];
        foreach ($this->files as $fileId => $file) {
            if ($file->fieldId !== $id) {
                $keptFiles[$fileId] = $file;
            }
        }
        $this->files = $keptFiles;
    }

    private function assertFieldNameUniqueInCategory(int $categoryId, string $name, ?int $exceptId): void
    {
        foreach ($this->fields as $f) {
            if ($f->categoryId === $categoryId && $f->name === $name && $f->id !== $exceptId) {
                throw new ValidationException(
                    field: 'name',
                    errorCode: \Imanager\Enum\InputErrorCode::WrongValueFormat,
                    message: \sprintf(
                        'Field name "%s" already exists in category %d',
                        $name,
                        $categoryId,
                    ),
                );
            }
        }
    }

    // ────────────────────────────── Items ────────────────────────────────

    public function getItem(int $id): ?Item
    {
        return $this->items[$id] ?? null;
    }

    /**
     * @return list<Item>
     */
    public function itemsByCategory(int $categoryId, int $offset = 0, int $limit = 0): array
    {
        $matching = [];
        foreach ($this->items as $it) {
            if ($it->categoryId === $categoryId) {
                $matching[] = $it;
            }
        }
        usort($matching, static fn(Item $a, Item $b): int => $a->position <=> $b->position);

        if ($offset > 0) {
            $matching = \array_slice($matching, $offset);
        }
        if ($limit > 0) {
            $matching = \array_slice($matching, 0, $limit);
        }
        return $matching;
    }

    public function countItemsByCategory(int $categoryId): int
    {
        $count = 0;
        foreach ($this->items as $it) {
            if ($it->categoryId === $categoryId) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return list<Item>
     */
    public function queryItems(\Imanager\Query\Query $query): array
    {
        $matching = $this->filterItems($query);
        $matching = $this->sortItems($matching, $query->orderBy);

        if ($query->offset > 0) {
            $matching = \array_slice($matching, $query->offset);
        }
        if ($query->limit > 0) {
            $matching = \array_slice($matching, 0, $query->limit);
        }
        return $matching;
    }

    public function countQueryItems(\Imanager\Query\Query $query): int
    {
        return \count($this->filterItems($query));
    }

    /**
     * @return list<Item>
     */
    private function filterItems(\Imanager\Query\Query $query): array
    {
        $out = [];
        foreach ($this->items as $item) {
            if ($query->categoryId !== null && $item->categoryId !== $query->categoryId) {
                continue;
            }
            $matches = true;
            foreach ($query->where as $clause) {
                if (! self::matchClause($item, $clause)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * @param list<Item>                    $items
     * @param list<\Imanager\Query\OrderBy> $orderings
     *
     * @return list<Item>
     */
    private static function sortItems(array $items, array $orderings): array
    {
        if ($orderings === []) {
            usort($items, static fn(Item $a, Item $b): int => $a->position <=> $b->position);
            return $items;
        }

        usort($items, static function (Item $a, Item $b) use ($orderings): int {
            foreach ($orderings as $order) {
                $av = self::fieldValue($a, $order->field);
                $bv = self::fieldValue($b, $order->field);
                $cmp = self::compare($av, $bv);
                if ($cmp !== 0) {
                    return $order->direction === \Imanager\Query\Direction::Desc ? -$cmp : $cmp;
                }
            }
            return 0;
        });

        return $items;
    }

    private static function matchClause(Item $item, \Imanager\Query\Clause $clause): bool
    {
        $value = self::fieldValue($item, $clause->field);

        return match ($clause->op) {
            \Imanager\Query\Operator::Eq => self::compare($value, $clause->value) === 0,
            \Imanager\Query\Operator::Neq => self::compare($value, $clause->value) !== 0,
            \Imanager\Query\Operator::Lt => self::compare($value, $clause->value) < 0,
            \Imanager\Query\Operator::Lte => self::compare($value, $clause->value) <= 0,
            \Imanager\Query\Operator::Gt => self::compare($value, $clause->value) > 0,
            \Imanager\Query\Operator::Gte => self::compare($value, $clause->value) >= 0,
            \Imanager\Query\Operator::Like => self::likeMatch(
                $value === null ? '' : (string) $value,
                (string) $clause->value,
            ),
        };
    }

    private static function fieldValue(Item $item, string $field): mixed
    {
        return match ($field) {
            'id' => $item->id,
            'category_id', 'categoryId' => $item->categoryId,
            'name' => $item->name,
            'label' => $item->label,
            'position' => $item->position,
            'active' => $item->active,
            'created' => $item->created,
            'updated' => $item->updated,
            default => $item->data->get($field),
        };
    }

    /**
     * Loose comparison that lets selector strings like `position>=3` work even
     * when the value comes in as the string `"3"`. Numeric strings on either
     * side are compared numerically; everything else falls back to PHP's
     * spaceship operator.
     */
    private static function compare(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return ((float) $a) <=> ((float) $b);
        }
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return -1;
        }
        if ($b === null) {
            return 1;
        }
        return $a <=> $b;
    }

    private static function likeMatch(string $haystack, string $pattern): bool
    {
        $regex = '/^' . str_replace(
            ['%', '_'],
            ['.*', '.'],
            preg_quote($pattern, '/'),
        ) . '$/iu';
        return preg_match($regex, $haystack) === 1;
    }

    public function saveItem(Item $item): Item
    {
        $now = time();

        if (! isset($this->categories[$item->categoryId])) {
            throw new StorageException(\sprintf(
                'Cannot save item: category %d does not exist',
                $item->categoryId,
            ));
        }

        if ($item->id === null) {
            $id = $this->nextItemId++;
            $stored = new Item(
                id: $id,
                categoryId: $item->categoryId,
                name: $item->name,
                label: $item->label,
                position: $item->position,
                active: $item->active,
                data: $item->data,
                created: $item->created !== 0 ? $item->created : $now,
                updated: $now,
            );
            $this->items[$id] = $stored;
            return $stored;
        }

        if (! isset($this->items[$item->id])) {
            throw NotFoundException::item($item->categoryId, $item->id);
        }

        $id = $item->id;
        $previous = $this->items[$id];
        $stored = new Item(
            id: $id,
            categoryId: $item->categoryId,
            name: $item->name,
            label: $item->label,
            position: $item->position,
            active: $item->active,
            data: $item->data,
            created: $item->created !== 0 ? $item->created : $previous->created,
            updated: $now,
        );
        $this->items[$id] = $stored;
        return $stored;
    }

    public function deleteItem(int $id): void
    {
        if (! isset($this->items[$id])) {
            throw NotFoundException::item(0, $id);
        }
        unset($this->items[$id]);

        $keptFiles = [];
        foreach ($this->files as $fileId => $file) {
            if ($file->itemId !== $id) {
                $keptFiles[$fileId] = $file;
            }
        }
        $this->files = $keptFiles;
    }

    // ────────────────────────────── Files ────────────────────────────────

    public function getFile(int $id): ?\Imanager\Domain\File
    {
        return $this->files[$id] ?? null;
    }

    /**
     * @return list<\Imanager\Domain\File>
     */
    public function filesByItem(int $itemId): array
    {
        $out = [];
        foreach ($this->files as $f) {
            if ($f->itemId === $itemId) {
                $out[] = $f;
            }
        }
        usort(
            $out,
            static fn(\Imanager\Domain\File $a, \Imanager\Domain\File $b): int
                => $a->position <=> $b->position ?: ($a->id ?? 0) <=> ($b->id ?? 0),
        );
        return $out;
    }

    /**
     * @return list<\Imanager\Domain\File>
     */
    public function filesByItemAndField(int $itemId, int $fieldId): array
    {
        $out = [];
        foreach ($this->filesByItem($itemId) as $f) {
            if ($f->fieldId === $fieldId) {
                $out[] = $f;
            }
        }
        return $out;
    }

    public function saveFile(\Imanager\Domain\File $file): \Imanager\Domain\File
    {
        $now = time();

        if (! isset($this->items[$file->itemId])) {
            throw new \Imanager\Exception\StorageException(\sprintf(
                'Cannot save file: item %d does not exist',
                $file->itemId,
            ));
        }
        if (! isset($this->fields[$file->fieldId])) {
            throw new \Imanager\Exception\StorageException(\sprintf(
                'Cannot save file: field %d does not exist',
                $file->fieldId,
            ));
        }

        if ($file->id === null) {
            $id = $this->nextFileId++;
            $stored = new \Imanager\Domain\File(
                id: $id,
                itemId: $file->itemId,
                fieldId: $file->fieldId,
                name: $file->name,
                path: $file->path,
                mime: $file->mime,
                size: $file->size,
                width: $file->width,
                height: $file->height,
                position: $file->position,
                created: $file->created !== 0 ? $file->created : $now,
                title: $file->title,
            );
            $this->files[$id] = $stored;
            return $stored;
        }

        if (! isset($this->files[$file->id])) {
            throw NotFoundException::item(0, $file->id);
        }

        $id = $file->id;
        $previous = $this->files[$id];
        $stored = new \Imanager\Domain\File(
            id: $id,
            itemId: $file->itemId,
            fieldId: $file->fieldId,
            name: $file->name,
            path: $file->path,
            mime: $file->mime,
            size: $file->size,
            width: $file->width,
            height: $file->height,
            position: $file->position,
            created: $file->created !== 0 ? $file->created : $previous->created,
            title: $file->title,
        );
        $this->files[$id] = $stored;
        return $stored;
    }

    public function deleteFile(int $id): void
    {
        if (! isset($this->files[$id])) {
            throw NotFoundException::item(0, $id);
        }
        unset($this->files[$id]);
    }
}
