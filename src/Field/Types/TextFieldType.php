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

final readonly class TextFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Text->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'maxLength' => 255,
            'minLength' => 0,
            'placeholder' => '',
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $maxLength = (int) ($config['maxLength'] ?? 255);
        $minLength = (int) ($config['minLength'] ?? 0);

        $coerced = $this->sanitizer->text($this->stringify($rawValue), $maxLength);

        if ($field->required && $coerced === '') {
            return ValidationResult::failed(InputErrorCode::EmptyRequired);
        }
        if ($coerced !== '' && mb_strlen($coerced, 'UTF-8') < $minLength) {
            return ValidationResult::failed(InputErrorCode::MinLengthExceeded);
        }
        return ValidationResult::ok($coerced);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $maxLength = (int) ($config['maxLength'] ?? 255);
        $placeholder = (string) ($config['placeholder'] ?? '');

        $name = $this->sanitizer->entities($context->inputName);
        $valueAttr = $this->sanitizer->entities($this->stringify($value));
        $placeholderAttr = $placeholder !== ''
            ? \sprintf(' placeholder="%s"', $this->sanitizer->entities($placeholder))
            : '';
        $required = $field->required ? ' required' : '';

        return \sprintf(
            '<input type="text" name="%s" value="%s" maxlength="%d"%s%s>',
            $name,
            $valueAttr,
            $maxLength,
            $placeholderAttr,
            $required,
        );
    }

    private function stringify(mixed $value): string
    {
        if ($value === null || \is_array($value)) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? '1' : '';
        }
        return (string) $value;
    }
}
