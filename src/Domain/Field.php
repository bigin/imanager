<?php

declare(strict_types=1);

namespace Imanager\Domain;

use Imanager\Enum\FieldType;

/**
 * A field definition that belongs to a category.
 *
 * `categoryId` is the **owning** category — for the field to make sense, that
 * category must already exist; the constructor enforces `>= 1` accordingly,
 * the storage layer enforces FK referential integrity.
 *
 * The `config` array is intentionally untyped here; the FieldType plugin
 * decides its shape (Phase 7). For Phase 6 it round-trips as opaque data.
 *
 * For ergonomic schema setup (2.1+), prefer the type-named static factories
 * + fluent setters over the long named-argument constructor:
 *
 *   Field::text($categoryId, 'title', 'Title')
 *       ->required()->indexed()->searchable()->maxLength(200);
 *
 * Each setter returns a new `Field` (immutable value-object semantics
 * preserved — no mutable builder, no two-phase init).
 */
final readonly class Field
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public ?int $id,
        public int $categoryId,
        public string $name,
        public ?string $label,
        public FieldType $type,
        public int $position = 0,
        public bool $required = false,
        public bool $indexed = false,
        public bool $searchable = false,
        public array $config = [],
        public int $created = 0,
        public int $updated = 0,
    ) {
        if ($id !== null && $id < 1) {
            throw new \InvalidArgumentException('Field id, when set, must be >= 1');
        }
        if ($categoryId < 1) {
            throw new \InvalidArgumentException('Field categoryId must be >= 1');
        }
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Field name must not be empty');
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('Field position must be >= 0');
        }
        if ($created < 0 || $updated < 0) {
            throw new \InvalidArgumentException('Field timestamps must be >= 0');
        }
    }

    // ---------------------------------------------------------------------
    // Static factories — one per FieldType case. All return a fresh
    // (id = null) Field with default flags + empty config.
    // ---------------------------------------------------------------------

    public static function text(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Text);
    }

    public static function longText(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::LongText);
    }

    public static function editor(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Editor);
    }

    public static function slug(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Slug);
    }

    public static function password(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Password);
    }

    public static function integer(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Integer);
    }

    public static function decimal(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Decimal);
    }

    public static function money(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Money);
    }

    public static function checkbox(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Checkbox);
    }

    public static function dropdown(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Dropdown);
    }

    public static function datepicker(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Datepicker);
    }

    public static function hidden(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Hidden);
    }

    public static function arrayList(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::ArrayList);
    }

    public static function file(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Fileupload);
    }

    public static function image(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Imageupload);
    }

    public static function filePicker(int $categoryId, string $name, ?string $label = null): self
    {
        return new self(null, $categoryId, $name, $label, FieldType::Filepicker);
    }

    // ---------------------------------------------------------------------
    // Fluent setters — general. Each returns a new instance.
    // ---------------------------------------------------------------------

    public function withId(int $id): self
    {
        return $this->copy(['id' => $id]);
    }

    public function required(bool $required = true): self
    {
        return $this->copy(['required' => $required]);
    }

    public function indexed(bool $indexed = true): self
    {
        return $this->copy(['indexed' => $indexed]);
    }

    public function searchable(bool $searchable = true): self
    {
        return $this->copy(['searchable' => $searchable]);
    }

    public function position(int $position): self
    {
        return $this->copy(['position' => $position]);
    }

    public function label(string $label): self
    {
        return $this->copy(['label' => $label]);
    }

    /**
     * Replace the config array wholesale. Use the type-aware setters
     * (`maxLength`, `mimes`, …) for incremental edits.
     *
     * @param array<string, mixed> $config
     */
    public function config(array $config): self
    {
        return $this->copy(['config' => $config]);
    }

    // ---------------------------------------------------------------------
    // Fluent setters — type-aware. Each writes one documented key into
    // `config`. Setters that don't apply to the field type are silently
    // no-op: the plugin's `validate()` simply ignores unrecognised keys.
    // ---------------------------------------------------------------------

    public function maxLength(int $chars): self
    {
        return $this->withConfigKey('maxLength', $chars);
    }

    public function minLength(int $chars): self
    {
        return $this->withConfigKey('minLength', $chars);
    }

    public function placeholder(string $text): self
    {
        return $this->withConfigKey('placeholder', $text);
    }

    public function maxBytes(int $bytes): self
    {
        return $this->withConfigKey('maxBytes', $bytes);
    }

    /**
     * @param string ...$mimes One or more MIME types (e.g. 'image/jpeg').
     */
    public function mimes(string ...$mimes): self
    {
        return $this->withConfigKey('mimes', $mimes);
    }

    /**
     * @param array<int|string, mixed> $options
     */
    public function options(array $options): self
    {
        return $this->withConfigKey('options', $options);
    }

    public function format(string $format): self
    {
        return $this->withConfigKey('format', $format);
    }

    // ---------------------------------------------------------------------
    // Private helpers.
    // ---------------------------------------------------------------------

    /**
     * Return a new instance with the given key in `config` replaced.
     * Other config keys are preserved.
     */
    private function withConfigKey(string $key, mixed $value): self
    {
        return $this->copy(['config' => [$key => $value] + $this->config]);
    }

    /**
     * One-stop copy helper. Keys present in `$changes` overwrite the
     * matching properties; everything else carries over. `label` uses
     * `array_key_exists()` because it's the only nullable storage value
     * — so callers can clear it by passing `['label' => null]`.
     *
     * @param array<string, mixed> $changes
     */
    private function copy(array $changes): self
    {
        return new self(
            id: $changes['id']         ?? $this->id,
            categoryId: $changes['categoryId'] ?? $this->categoryId,
            name: $changes['name']       ?? $this->name,
            label: \array_key_exists('label', $changes) ? $changes['label'] : $this->label,
            type: $changes['type']       ?? $this->type,
            position: $changes['position']   ?? $this->position,
            required: $changes['required']   ?? $this->required,
            indexed: $changes['indexed']    ?? $this->indexed,
            searchable: $changes['searchable'] ?? $this->searchable,
            config: $changes['config']     ?? $this->config,
            created: $changes['created']    ?? $this->created,
            updated: $changes['updated']    ?? $this->updated,
        );
    }
}
