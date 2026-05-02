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
 * Decimal number with optional precision, min, max.
 *
 * Storage is `float` — keep this in mind for use cases that require exact
 * arithmetic (use the `Money` field type, which adds currency context, or
 * store cents as `Integer`).
 */
final readonly class DecimalFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Decimal->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Real;
    }

    public function defaultConfig(): array
    {
        return [
            'min' => null,
            'max' => null,
            'precision' => 2,
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        if (! $field->required && ($rawValue === null || $rawValue === '')) {
            return ValidationResult::ok(null);
        }
        if ($field->required && ($rawValue === null || $rawValue === '')) {
            return ValidationResult::failed(InputErrorCode::EmptyRequired);
        }
        if (! is_numeric($rawValue)) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }

        $config = [...$this->defaultConfig(), ...$field->config];
        $min = is_numeric($config['min'] ?? null) ? (float) $config['min'] : null;
        $max = is_numeric($config['max'] ?? null) ? (float) $config['max'] : null;
        $precision = max(0, (int) ($config['precision'] ?? 2));

        $coerced = $this->sanitizer->float($rawValue, $min, $max);
        $coerced = round($coerced, $precision);

        return ValidationResult::ok($coerced);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $min = $config['min'] ?? null;
        $max = $config['max'] ?? null;
        $precision = max(0, (int) ($config['precision'] ?? 2));
        $step = $precision > 0 ? '0.' . str_repeat('0', $precision - 1) . '1' : '1';

        $name = $this->sanitizer->entities($context->inputName);
        $valueAttr = is_numeric($value)
            ? $this->sanitizer->entities(number_format((float) $value, $precision, '.', ''))
            : '';
        $minAttr = is_numeric($min) ? \sprintf(' min="%s"', $min) : '';
        $maxAttr = is_numeric($max) ? \sprintf(' max="%s"', $max) : '';
        $required = $field->required ? ' required' : '';

        return \sprintf(
            '<input type="number" name="%s" value="%s" step="%s"%s%s%s>',
            $name,
            $valueAttr,
            $step,
            $minAttr,
            $maxAttr,
            $required,
        );
    }
}
