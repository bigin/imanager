<?php

declare(strict_types=1);

namespace Imanager\Migration;

/**
 * Result of a {@see JsonV1Importer::import()} call.
 *
 * Mutable on purpose — the importer fills it as it walks the source tree.
 * Callers only read it back. After `import()` returns, treat the report as
 * effectively immutable.
 */
final class ImportReport
{
    public int $categoriesImported = 0;
    public int $fieldsImported = 0;
    public int $itemsImported = 0;
    public int $itemsRemapped = 0;
    public int $assetsCopied = 0;
    public bool $rolledBack = false;

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function summary(): string
    {
        $remappedSuffix = $this->itemsRemapped > 0
            ? \sprintf(', remapped %d item reference(s)', $this->itemsRemapped)
            : '';
        return \sprintf(
            'Imported %d categories, %d fields, %d items; copied %d assets%s; %d errors, %d warnings%s',
            $this->categoriesImported,
            $this->fieldsImported,
            $this->itemsImported,
            $this->assetsCopied,
            $remappedSuffix,
            \count($this->errors),
            \count($this->warnings),
            $this->rolledBack ? ' (rolled back: dry run or fatal error)' : '',
        );
    }
}
