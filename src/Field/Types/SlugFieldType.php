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

final readonly class SlugFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Slug->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'maxLength' => 128,
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $maxLength = (int) ($config['maxLength'] ?? 128);

        $coerced = $this->sanitizer->slug($this->stringify($rawValue), $maxLength);

        if ($field->required && $coerced === '') {
            return ValidationResult::failed(InputErrorCode::EmptyRequired);
        }
        return ValidationResult::ok($coerced);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $maxLength = (int) ($config['maxLength'] ?? 128);

        $name = $this->sanitizer->entities($context->inputName);
        $valueAttr = $this->sanitizer->entities($this->stringify($value));
        $required = $field->required ? ' required' : '';

        return \sprintf(
            '<input type="text" name="%s" value="%s" maxlength="%d" pattern="[a-z0-9-]+"%s>',
            $name,
            $valueAttr,
            $maxLength,
            $required,
        );
    }

    private function stringify(mixed $value): string
    {
        if ($value === null || \is_array($value) || \is_bool($value)) {
            return '';
        }
        return (string) $value;
    }
}
