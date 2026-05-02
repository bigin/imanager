<?php

declare(strict_types=1);

namespace Imanager\Field;

/**
 * Context passed to {@see FieldTypePlugin::render()} when producing form input markup.
 *
 * Phase 7b ships only the bits every input needs (HTML name attribute and
 * the owning item id, which may be null when creating new). Phase 14's admin
 * UI will extend this with CSRF tokens, upload endpoints, locale, etc., as
 * those concerns land — the value object is intentionally minimal until then
 * so plugins don't depend on context they don't yet use.
 */
final readonly class RenderContext
{
    public function __construct(
        public string $inputName,
        public ?int $itemId = null,
    ) {}
}
