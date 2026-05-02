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
 * Single-select from a fixed list of options.
 *
 * Config:
 *   options: array<string, string>   (value => label, in display order)
 *
 * The validated value is the option *key* — the label is purely a display
 * concern. An empty/non-required selection rounds to `null`.
 */
final readonly class DropdownFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Dropdown->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'options' => [],
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        $options = $this->options($field);

        if ($rawValue === null || $rawValue === '') {
            if ($field->required) {
                return ValidationResult::failed(InputErrorCode::EmptyRequired);
            }
            return ValidationResult::ok(null);
        }

        $key = (string) $rawValue;
        if (! \array_key_exists($key, $options)) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }
        return ValidationResult::ok($key);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $options = $this->options($field);
        $current = \is_string($value) ? $value : '';

        $name = $this->sanitizer->entities($context->inputName);
        $required = $field->required ? ' required' : '';

        $optionsHtml = '';
        if (! $field->required) {
            $optionsHtml .= '<option value=""></option>';
        }
        foreach ($options as $key => $label) {
            $optionsHtml .= \sprintf(
                '<option value="%s"%s>%s</option>',
                $this->sanitizer->entities((string) $key),
                $key === $current ? ' selected' : '',
                $this->sanitizer->entities((string) $label),
            );
        }

        return \sprintf(
            '<select name="%s"%s>%s</select>',
            $name,
            $required,
            $optionsHtml,
        );
    }

    /**
     * @return array<string, string>
     */
    private function options(Field $field): array
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $options = $config['options'] ?? [];
        if (! \is_array($options)) {
            return [];
        }
        $clean = [];
        foreach ($options as $key => $label) {
            $clean[(string) $key] = (string) $label;
        }
        return $clean;
    }
}
