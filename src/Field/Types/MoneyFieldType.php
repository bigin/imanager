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
 * Money amount: a `Decimal` with currency-code context.
 *
 * Stored as a `float`. The currency code is *not* part of the stored value —
 * it lives on the field config. If a use case ever needs per-row currency,
 * promote this to a small struct and serialize as JSON; that's a Phase 7+
 * decision a downstream module can make without touching the storage layer.
 */
final readonly class MoneyFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Money->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Real;
    }

    public function defaultConfig(): array
    {
        return [
            'currency' => 'EUR',
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

        // Tolerate "1.234,56" / "1,234.56" — strip thousand separators based
        // on the dominant separator before parsing.
        $normalized = \is_string($rawValue) ? $this->normalizeMoneyString($rawValue) : $rawValue;
        if (! is_numeric($normalized)) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }

        $config = [...$this->defaultConfig(), ...$field->config];
        $min = is_numeric($config['min'] ?? null) ? (float) $config['min'] : null;
        $max = is_numeric($config['max'] ?? null) ? (float) $config['max'] : null;
        $precision = max(0, (int) ($config['precision'] ?? 2));

        $coerced = $this->sanitizer->float($normalized, $min, $max);
        $coerced = round($coerced, $precision);

        return ValidationResult::ok($coerced);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $currency = $this->sanitizer->entities((string) ($config['currency'] ?? 'EUR'));
        $precision = max(0, (int) ($config['precision'] ?? 2));
        $step = $precision > 0 ? '0.' . str_repeat('0', $precision - 1) . '1' : '1';
        $min = $config['min'] ?? null;
        $max = $config['max'] ?? null;

        $name = $this->sanitizer->entities($context->inputName);
        $valueAttr = is_numeric($value)
            ? $this->sanitizer->entities(number_format((float) $value, $precision, '.', ''))
            : '';
        $minAttr = is_numeric($min) ? \sprintf(' min="%s"', $min) : '';
        $maxAttr = is_numeric($max) ? \sprintf(' max="%s"', $max) : '';
        $required = $field->required ? ' required' : '';

        return \sprintf(
            '<input type="number" name="%s" value="%s" step="%s" data-currency="%s"%s%s%s>',
            $name,
            $valueAttr,
            $step,
            $currency,
            $minAttr,
            $maxAttr,
            $required,
        );
    }

    private function normalizeMoneyString(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }
        // Strip currency symbols, spaces.
        $value = preg_replace('/[^0-9.,\-]/', '', $value) ?? '';
        if ($value === '') {
            return $value;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // The rightmost punctuation is the decimal separator; the other
            // is a thousand separator.
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            $value = str_replace(',', '.', $value);
        }
        return $value;
    }
}
