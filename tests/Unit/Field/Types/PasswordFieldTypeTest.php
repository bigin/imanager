<?php

declare(strict_types=1);

namespace Imanager\Tests\Unit\Field\Types;

use Imanager\Domain\Field;
use Imanager\Enum\FieldType;
use Imanager\Enum\InputErrorCode;
use Imanager\Field\RenderContext;
use Imanager\Field\Types\PasswordFieldType;
use Imanager\Validation\Sanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordFieldType::class)]
final class PasswordFieldTypeTest extends TestCase
{
    private PasswordFieldType $plugin;

    protected function setUp(): void
    {
        $this->plugin = new PasswordFieldType(new Sanitizer());
    }

    public function testValidateOnEmptyReturnsNullToSignalNoChange(): void
    {
        $result = $this->plugin->validate('', $this->field());

        self::assertTrue($result->isValid);
        self::assertNull($result->coerced);
    }

    public function testValidateOnEmptyOfRequiredFieldStillReturnsNull(): void
    {
        // The "leave existing alone" sentinel applies regardless of required;
        // the create-flow is responsible for refusing to save without a hash.
        $result = $this->plugin->validate('', $this->field(required: true));

        self::assertTrue($result->isValid);
        self::assertNull($result->coerced);
    }

    public function testValidateHashesPlaintextOnNonEmptyInput(): void
    {
        $result = $this->plugin->validate('correct horse battery staple', $this->field());

        self::assertTrue($result->isValid);
        self::assertIsString($result->coerced);
        self::assertStringStartsWith('$2y$', $result->coerced);
        self::assertTrue(password_verify('correct horse battery staple', $result->coerced));
    }

    public function testValidateRejectsBelowMinLength(): void
    {
        $field = $this->field(['minLength' => 8]);
        $result = $this->plugin->validate('short', $field);

        self::assertFalse($result->isValid);
        self::assertSame(InputErrorCode::MinLengthExceeded, $result->errorCode);
    }

    public function testRenderNeverEchoesValueAndAdvertisesNewPasswordAutocomplete(): void
    {
        $html = $this->plugin->render(
            '$2y$10$alreadyhashed',
            $this->field(),
            new RenderContext('item[password]'),
        );

        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('value=""', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertStringContainsString('placeholder="(unchanged)"', $html);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function field(array $config = [], bool $required = false): Field
    {
        return new Field(
            id: 1,
            categoryId: 1,
            name: 'password',
            label: 'Password',
            type: FieldType::Password,
            required: $required,
            config: $config,
        );
    }
}
