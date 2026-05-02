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
 * Password field. Stores a bcrypt hash of the submitted plaintext.
 *
 * **Empty input is a sentinel: it means "leave the existing hash alone".**
 * `validate()` returns `ValidationResult::ok(null)` in that case; the
 * upstream save path (Phase 14's editor controller) interprets `null` as
 * "skip writing this field". This avoids a footgun where re-saving an item
 * with a blank password input would silently blank the password.
 *
 * On non-empty input, the plaintext is hashed with `PASSWORD_BCRYPT`
 * (PHP-pinned to PASSWORD_DEFAULT current default) and the resulting hash
 * is stored. Plaintext never reaches `Item->data`.
 */
final readonly class PasswordFieldType implements FieldTypePlugin
{
    public function __construct(private Sanitizer $sanitizer) {}

    public static function name(): string
    {
        return FieldType::Password->value;
    }

    public static function affinity(): SqliteAffinity
    {
        return SqliteAffinity::Text;
    }

    public function defaultConfig(): array
    {
        return [
            'minLength' => 8,
            'placeholder' => '(unchanged)',
        ];
    }

    public function validate(mixed $rawValue, Field $field): ValidationResult
    {
        if ($rawValue === null || $rawValue === '') {
            // Empty == "no change" — even on a required field. Initial-create
            // flow is the caller's responsibility (it can refuse to save the
            // item if the bag still lacks the key).
            return ValidationResult::ok(null);
        }
        if (! \is_string($rawValue)) {
            return ValidationResult::failed(InputErrorCode::WrongValueFormat);
        }

        $config = [...$this->defaultConfig(), ...$field->config];
        $minLength = (int) ($config['minLength'] ?? 8);
        if (\strlen($rawValue) < $minLength) {
            return ValidationResult::failed(InputErrorCode::MinLengthExceeded);
        }

        $hash = password_hash($rawValue, \PASSWORD_BCRYPT);
        return ValidationResult::ok($hash);
    }

    public function render(mixed $value, Field $field, RenderContext $context): string
    {
        $config = [...$this->defaultConfig(), ...$field->config];
        $placeholder = (string) ($config['placeholder'] ?? '');
        $minLength = (int) ($config['minLength'] ?? 8);

        $name = $this->sanitizer->entities($context->inputName);
        $placeholderAttr = $placeholder !== ''
            ? \sprintf(' placeholder="%s"', $this->sanitizer->entities($placeholder))
            : '';

        // Value is intentionally never echoed — password inputs always start
        // empty. The placeholder hints to the user that empty == unchanged.
        return \sprintf(
            '<input type="password" name="%s" value="" minlength="%d"%s autocomplete="new-password">',
            $name,
            $minLength,
            $placeholderAttr,
        );
    }
}
