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
 * Date input. Stores Unix timestamps so that range queries against the
 * indexed generated column (Phase 4) are pure integer comparisons.
 */
final readonly class DatepickerFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Datepicker->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Integer;
    }

    public function defaultConfig(): array
    {
        return [
            'min' => null,    // ISO date string or unix timestamp
            'max' => null,
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        if ($rawValue === null || $rawValue === '') {
            if ($field->required) {
                return ValidationResult::failed(InputErrorCode::EmptyRequired);
            }
            return ValidationResult::ok(null);
        }

        if (\is_int($rawValue)) {
            return ValidationResult::ok($rawValue);
        }
        if (! \is_string($rawValue)) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }

        // Accept ISO-8601 dates, "YYYY-MM-DD HH:MM:SS", or anything strtotime
        // groks. Reject input that doesn't parse.
        $timestamp = strtotime($rawValue);
        if ($timestamp === false) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }
        return ValidationResult::ok($timestamp);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $name = $this->sanitizer->entities($context->inputName);
        $required = $field->required ? ' required' : '';

        $valueAttr = '';
        if (\is_int($value)) {
            $formatted = date('Y-m-d', $value);
            $valueAttr = $this->sanitizer->entities($formatted);
        }

        return \sprintf(
            '<input type="date" name="%s" value="%s"%s>',
            $name,
            $valueAttr,
            $required,
        );
    }
}
