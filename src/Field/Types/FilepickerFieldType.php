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
 * Pick an existing already-uploaded file (path within `data/uploads`).
 *
 * **Phase-13 stub.** The full implementation needs the upload-storage layer
 * to exist so `render()` can populate a `<select>` of available files.
 * Until that lands, this plugin treats the value as an opaque sanitized
 * filename and renders a plain text input. The plugin contract is
 * satisfied; behavior is intentionally minimal so a Phase-13 follow-up
 * can replace `render()` without touching `validate()`.
 */
final readonly class FilepickerFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Filepicker->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'acceptedExtensions' => 'gif|jpe?g|png|pdf',
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
        if (! \is_string($rawValue)) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }

        $name = $this->sanitizer->filename($rawValue);
        if ($name === '') {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }
        return ValidationResult::ok($name);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $name = $this->sanitizer->entities($context->inputName);
        $valueAttr = \is_string($value) ? $this->sanitizer->entities($value) : '';
        $required = $field->required ? ' required' : '';

        // Phase-13 will replace this with a populated <select> driven by the
        // upload directory. The data-attribute marks the upgrade point.
        return \sprintf(
            '<input type="text" name="%s" value="%s" data-field="filepicker"%s>',
            $name,
            $valueAttr,
            $required,
        );
    }
}
