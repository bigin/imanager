<?php

declare(strict_types=1);

namespace Imanager\Templating;

/**
 * Minimal `{{var}}` substitution renderer.
 *
 * Replaces iManager 1.x's `[[var]]` parser. The new syntax matches the
 * mustache / handlebars convention so editor users have prior intuition,
 * and the regex sweep at the end strips any unmatched placeholders so a
 * template author's typo can't leak markup-as-text to the page.
 *
 * **No escaping policy.** This renderer is a pure string substitution:
 * the caller is responsible for entity-encoding values that need it
 * (typically via `Sanitizer::entities()`). That keeps the renderer
 * useful for both raw HTML composition (the {@see PaginationRenderer}
 * stitches pre-rendered fragments together) and user-text rendering
 * (where the caller pre-escapes).
 *
 * Variable values may be `string|int|float|bool|null|\Stringable`.
 * Other types are skipped — their placeholders fall through to the
 * unmatched-placeholder strip and disappear.
 */
final readonly class TemplateRenderer
{
    private const VAR_PATTERN = '/\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}/';

    /**
     * @param array<string, string|int|float|bool|\Stringable|null> $vars
     */
    public function render(string $template, array $vars = []): string
    {
        $output = $template;

        foreach ($vars as $name => $value) {
            $placeholder = '{{' . $name . '}}';
            $replacement = self::stringify($value);
            if ($replacement === null) {
                continue;
            }
            $output = str_replace($placeholder, $replacement, $output);
        }

        // Strip placeholders that didn't match any variable so a typo
        // doesn't render as visible text in the page.
        return (string) preg_replace(self::VAR_PATTERN, '', $output);
    }

    /**
     * @param array<string, string|int|float|bool|\Stringable|null> $vars
     */
    public function renderFile(string $path, array $vars = []): string
    {
        $template = @file_get_contents($path);
        if ($template === false) {
            throw new TemplateException(\sprintf('Cannot read template "%s"', $path));
        }
        return $this->render($template, $vars);
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_bool($value)) {
            return $value ? '1' : '';
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        return null;
    }
}
