<?php

declare(strict_types=1);

namespace Imanager\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Enum\SqliteAffinity;
use Imanager\Field\FieldTypePlugin;
use Imanager\Field\RenderContext;
use Imanager\Field\ValidationResult;
use Imanager\Validation\Sanitizer;

/**
 * Ordered list of short string values (tags, allowed-domains, etc.).
 *
 * Editor-input is a textarea, one value per line; storage is `list<string>`
 * (which round-trips through Item.data's JSON column). For complex per-row
 * values (rich tags, key/value pairs) build a domain-specific Field plugin
 * — this one stays deliberately simple.
 */
final readonly class ArrayListFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::ArrayList->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'maxItems' => 100,
            'itemMaxLength' => 255,
            'rows' => 6,
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $maxItems = (int) ($config['maxItems'] ?? 100);
        $itemMaxLength = (int) ($config['itemMaxLength'] ?? 255);

        $items = $this->parseInput($rawValue, $itemMaxLength);

        if ($field->required && $items === []) {
            return ValidationResult::failed(InputErrorCode::EmptyRequired);
        }
        if (\count($items) > $maxItems) {
            $items = \array_slice($items, 0, $maxItems);
        }
        return ValidationResult::ok($items);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $rows = (int) ($config['rows'] ?? 6);

        $body = '';
        if (\is_array($value)) {
            $lines = [];
            foreach ($value as $item) {
                if (\is_string($item) || is_numeric($item)) {
                    $lines[] = (string) $item;
                }
            }
            $body = $this->sanitizer->entities(implode("\n", $lines));
        }

        $name = $this->sanitizer->entities($context->inputName);
        $required = $field->required ? ' required' : '';

        return \sprintf(
            '<textarea name="%s" rows="%d"%s data-list="newline">%s</textarea>',
            $name,
            $rows,
            $required,
            $body,
        );
    }

    /**
     * @return list<string>
     */
    private function parseInput(mixed $rawValue, int $itemMaxLength): array
    {
        $candidates = [];
        if (\is_array($rawValue)) {
            foreach ($rawValue as $entry) {
                if (\is_string($entry) || is_numeric($entry)) {
                    $candidates[] = (string) $entry;
                }
            }
        } elseif (\is_string($rawValue)) {
            // Accept newline- OR comma-separated lists.
            $candidates = preg_split('/[\r\n,]+/', $rawValue) ?: [];
        }

        $items = [];
        foreach ($candidates as $candidate) {
            $clean = $this->sanitizer->text($candidate, $itemMaxLength);
            if ($clean !== '') {
                $items[] = $clean;
            }
        }
        return $items;
    }
}
