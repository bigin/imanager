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

/**
 * Opaque string carried in a hidden form input.
 *
 * Used for state that shouldn't be user-edited but needs to round-trip
 * through the form (e.g. a workflow step, a parent reference). Validation
 * trims and length-caps; nothing else.
 */
final readonly class HiddenFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Hidden->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'maxLength' => 1024,
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $maxLength = (int) ($config['maxLength'] ?? 1024);

        $coerced = $this->sanitizer->text($this->stringify($rawValue), $maxLength);

        return ValidationResult::ok($coerced);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $name = $this->sanitizer->entities($context->inputName);
        $valueAttr = $this->sanitizer->entities($this->stringify($value));

        return \sprintf('<input type="hidden" name="%s" value="%s">', $name, $valueAttr);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null || \is_array($value) || \is_bool($value)) {
            return '';
        }
        return (string) $value;
    }
}
