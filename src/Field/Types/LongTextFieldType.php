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

final readonly class LongTextFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::LongText->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'maxLength' => 65535,
            'minLength' => 0,
            'rows' => 6,
            'placeholder' => '',
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $maxLength = (int) ($config['maxLength'] ?? 65535);
        $minLength = (int) ($config['minLength'] ?? 0);

        $coerced = $this->sanitizer->multiline($this->stringify($rawValue), $maxLength);

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
        $rows = (int) ($config['rows'] ?? 6);
        $placeholder = (string) ($config['placeholder'] ?? '');

        $name = $this->sanitizer->entities($context->inputName);
        $body = $this->sanitizer->entities($this->stringify($value));
        $placeholderAttr = $placeholder !== ''
            ? \sprintf(' placeholder="%s"', $this->sanitizer->entities($placeholder))
            : '';
        $required = $field->required ? ' required' : '';

        return \sprintf(
            '<textarea name="%s" rows="%d"%s%s>%s</textarea>',
            $name,
            $rows,
            $placeholderAttr,
            $required,
            $body,
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
