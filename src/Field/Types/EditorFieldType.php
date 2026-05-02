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
 * Rich-text editor field.
 *
 * Stores the *source* (markdown text or HTML), not the rendered output —
 * display-side rendering happens at the template level. Two modes:
 *
 *   - `markdown` (default): the value goes through `Sanitizer::multiline`
 *     and renders to HTML at display time via `Sanitizer::markdown` (which
 *     runs Parsedown in safe mode).
 *   - `html`: the value goes through `Sanitizer::html` (HTMLPurifier with a
 *     conservative allowlist) on save, so what's stored is already safe.
 *
 * The form input itself is a plain `<textarea>` with a `data-editor-mode`
 * attribute; the admin-side JS in Phase 14 swaps in a real WYSIWYG control
 * based on that hint.
 */
final readonly class EditorFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Editor->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'mode' => 'markdown',
            'maxLength' => 65535,
            'rows' => 12,
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $mode = (string) ($config['mode'] ?? 'markdown');
        $maxLength = (int) ($config['maxLength'] ?? 65535);

        $raw = $this->stringify($rawValue);

        $coerced = match ($mode) {
            'html' => $this->sanitizer->html(
                $this->sanitizer->multiline($raw, $maxLength),
            ),
            default => $this->sanitizer->multiline($raw, $maxLength),
        };

        if ($field->required && trim($coerced) === '') {
            return ValidationResult::failed(InputErrorCode::EmptyRequired);
        }
        return ValidationResult::ok($coerced);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $mode = (string) ($config['mode'] ?? 'markdown');
        $rows = (int) ($config['rows'] ?? 12);

        $name = $this->sanitizer->entities($context->inputName);
        $body = $this->sanitizer->entities($this->stringify($value));
        $modeAttr = $this->sanitizer->entities($mode);
        $required = $field->required ? ' required' : '';

        return \sprintf(
            '<textarea name="%s" rows="%d" data-editor-mode="%s"%s>%s</textarea>',
            $name,
            $rows,
            $modeAttr,
            $required,
            $body,
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
