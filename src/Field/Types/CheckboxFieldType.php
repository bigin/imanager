<?php

declare(strict_types=1);

namespace Imanager\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\SqliteAffinity;
use Imanager\Field\FieldTypePlugin;
use Imanager\Field\RenderContext;
use Imanager\Field\ValidationResult;
use Imanager\Validation\Sanitizer;

final readonly class CheckboxFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Checkbox->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Integer;
    }

    public function defaultConfig(): array
    {
        return [
            'label' => '',
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        // Unchecked HTML checkboxes don't post a value at all — null /
        // missing is therefore "false", not an error, even if `required`
        // is set. (Required-true on a boolean is an interactive UX
        // decision, not a storage-layer constraint.)
        return ValidationResult::ok($this->sanitizer->bool($rawValue));
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $label = (string) ($config['label'] ?? '');

        $name = $this->sanitizer->entities($context->inputName);
        $checked = $this->sanitizer->bool($value) ? ' checked' : '';

        $input = \sprintf(
            '<input type="checkbox" name="%s" value="1"%s>',
            $name,
            $checked,
        );

        if ($label === '') {
            return $input;
        }

        return \sprintf(
            '<label>%s %s</label>',
            $input,
            $this->sanitizer->entities($label),
        );
    }
}
