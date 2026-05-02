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
 * Multi-image upload.
 *
 * **Phase-13 stub.** Same caveats as {@see FileuploadFieldType}: real
 * upload handling, image processing (resize, thumbnail) and mime
 * validation land with the upload pipeline in Phase 13. Today the plugin
 * satisfies the contract by passing through an array of records and
 * rendering a `<input type="file" accept="image/*">`.
 */
final readonly class ImageuploadFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Imageupload->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'acceptedExtensions' => 'gif|jpe?g|png|webp',
            'maxFiles' => 10,
            'maxSizeBytes' => 8 * 1024 * 1024,
            'thumbWidth' => 150,
            'thumbHeight' => 0,
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        if ($rawValue === null || $rawValue === '' || $rawValue === []) {
            return ValidationResult::ok([]);
        }
        if (! \is_array($rawValue)) {
            return ValidationResult::ok([]);
        }

        $records = [];
        foreach ($rawValue as $entry) {
            if (\is_array($entry)) {
                $records[] = $entry;
            }
        }
        return ValidationResult::ok($records);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $max = (int) ($config['maxFiles'] ?? 10);

        $name = $this->sanitizer->entities($context->inputName);
        $multiple = $max > 1 ? ' multiple' : '';

        return \sprintf(
            '<input type="file" accept="image/*" name="%s[]"%s data-max-files="%d" data-field="imageupload">',
            $name,
            $multiple,
            $max,
        );
    }
}
