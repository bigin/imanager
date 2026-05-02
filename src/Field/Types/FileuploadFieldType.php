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
 * Multi-file upload.
 *
 * **Phase-13 stub.** The full implementation needs the multipart-upload
 * pipeline (Imanager\Upload, planned in Phase 13) to handle file storage,
 * mime sniffing, size limits, and thumbnail generation. Until then this
 * plugin satisfies the plugin contract by treating the value as an opaque
 * `list<array>` of file metadata records and emitting a vanilla
 * `<input type="file" multiple>`. The shape of each record will firm up in
 * Phase 13.
 */
final readonly class FileuploadFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Fileupload->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'acceptedExtensions' => 'gif|jpe?g|png|pdf|zip',
            'maxFiles' => 10,
            'maxSizeBytes' => 10 * 1024 * 1024,
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        // Phase-13's upload pipeline will replace this. For now, only accept
        // an array of records or null/empty.
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
        $accept = $this->sanitizer->entities((string) ($config['acceptedExtensions'] ?? ''));
        $max = (int) ($config['maxFiles'] ?? 10);

        $name = $this->sanitizer->entities($context->inputName);
        $multiple = $max > 1 ? ' multiple' : '';

        return \sprintf(
            '<input type="file" name="%s[]"%s data-accept="%s" data-max-files="%d" data-field="fileupload">',
            $name,
            $multiple,
            $accept,
            $max,
        );
    }
}
