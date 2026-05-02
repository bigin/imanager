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

final readonly class IntegerFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Integer->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Integer;
    }

    public function defaultConfig(): array
    {
        return [
            'min' => null,
            'max' => null,
            'step' => 1,
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        // Bare empty strings on a non-required integer field round-trip as null.
        if (! $field->required && ($rawValue === null || $rawValue === '')) {
            return ValidationResult::ok(null);
        }

        if ($field->required && ($rawValue === null || $rawValue === '')) {
            return ValidationResult::failed(InputErrorCode::EmptyRequired);
        }

        // Reject inputs that don't actually look like an integer before
        // coercion swallows them silently. `is_numeric` already covers
        // ints, floats, and numeric strings; `is_bool` keeps `true`/`false`
        // checkboxes alive even when binding to an integer field.
        if (! is_numeric($rawValue) && ! \is_bool($rawValue)) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }

        $config = [...$this->defaultConfig(), ...$field->config];
        $min = $config['min'] ?? null;
        $max = $config['max'] ?? null;

        $coerced = $this->sanitizer->int(
            $rawValue,
            \is_int($min) ? $min : null,
            \is_int($max) ? $max : null,
        );

        return ValidationResult::ok($coerced);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $min = $config['min'] ?? null;
        $max = $config['max'] ?? null;
        $step = (int) ($config['step'] ?? 1);

        $name = $this->sanitizer->entities($context->inputName);
        $valueAttr = $value === null
            ? ''
            : $this->sanitizer->entities((string) $this->sanitizer->int($value));
        $minAttr = \is_int($min) ? \sprintf(' min="%d"', $min) : '';
        $maxAttr = \is_int($max) ? \sprintf(' max="%d"', $max) : '';
        $required = $field->required ? ' required' : '';

        return \sprintf(
            '<input type="number" name="%s" value="%s" step="%d"%s%s%s>',
            $name,
            $valueAttr,
            $step,
            $minAttr,
            $maxAttr,
            $required,
        );
    }
}
