<?php

declare(strict_types=1);

namespace Imanager\Migration;

use Imanager\Exception\ImanagerException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Safe AST-based reader for iManager 1.x's `var_export()`-shaped data files.
 *
 * iManager 1.x persisted Categories, Fields and Items as PHP files of the
 * shape `<?php return array( 1 => Imanager\Item::__set_state(array(...)), );`.
 * `include`-ing those files is a remote-code-execution risk if the bytes on
 * disk have been tampered with — anything outside the literal `return` runs.
 *
 * This parser uses `nikic/php-parser` to read the file as an AST and walks
 * only the literal value expressions (scalars, arrays, `__set_state` static
 * calls). Unknown nodes raise `MigrationParseException`. No host code from
 * the source file ever executes.
 *
 * `__set_state` calls — the form `var_export` emits for any class that
 * implements it — are flattened to plain associative arrays. The original
 * fully-qualified class name lands under the `__class` key so the importer
 * can tell a `Category` from a `Field` from a custom `Page`. Nested calls
 * (e.g. a `FieldConfigs` inside a `Field`) flatten the same way.
 */
final readonly class V1FileParser
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function parseFile(string $path): array
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new MigrationParseException(\sprintf('Cannot read file "%s"', $path));
        }
        return $this->parseSource($source, $path);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function parseSource(string $source, string $context = '<source>'): array
    {
        try {
            $ast = $this->parser->parse($source);
        } catch (\PhpParser\Error $e) {
            throw new MigrationParseException(
                \sprintf('Syntax error in %s: %s', $context, $e->getMessage()),
                previous: $e,
            );
        }
        if ($ast === null) {
            throw new MigrationParseException(\sprintf('Empty AST for %s', $context));
        }

        foreach ($ast as $stmt) {
            if (! $stmt instanceof Stmt\Return_) {
                continue;
            }
            $expr = $stmt->expr;
            if ($expr === null) {
                return [];
            }
            $value = $this->extract($expr, $context);
            if (! \is_array($value)) {
                throw new MigrationParseException(
                    \sprintf('Top-level return in %s is not an array', $context),
                );
            }
            return $value;
        }

        throw new MigrationParseException(
            \sprintf('No top-level `return` statement in %s', $context),
        );
    }

    private function extract(Expr $expr, string $context): mixed
    {
        if ($expr instanceof Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Scalar\Int_) {
            return $expr->value;
        }
        if ($expr instanceof Scalar\Float_) {
            return $expr->value;
        }
        if ($expr instanceof Expr\ConstFetch) {
            $name = $expr->name->toString();
            return match (strtolower($name)) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => throw new MigrationParseException(
                    \sprintf('Unsupported constant "%s" in %s', $name, $context),
                ),
            };
        }
        if ($expr instanceof Expr\UnaryMinus) {
            $inner = $this->extract($expr->expr, $context);
            if (\is_int($inner) || \is_float($inner)) {
                return -$inner;
            }
            throw new MigrationParseException(
                \sprintf('Unary minus on non-numeric in %s', $context),
            );
        }
        if ($expr instanceof Expr\Array_) {
            return $this->extractArray($expr, $context);
        }
        if ($expr instanceof Expr\StaticCall) {
            return $this->extractStaticCall($expr, $context);
        }

        throw new MigrationParseException(\sprintf(
            'Unsupported expression `%s` in %s',
            $expr->getType(),
            $context,
        ));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function extractArray(Expr\Array_ $array, string $context): array
    {
        $result = [];
        foreach ($array->items as $item) {
            if ($item === null) {
                // Sparse arrays like `[1, , 3]` — `var_export()` never emits these.
                continue;
            }
            $value = $this->extract($item->value, $context);
            if ($item->key === null) {
                $result[] = $value;
                continue;
            }
            $key = $this->extract($item->key, $context);
            if (! \is_int($key) && ! \is_string($key)) {
                throw new MigrationParseException(
                    \sprintf('Array key must be int|string in %s', $context),
                );
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractStaticCall(Expr\StaticCall $call, string $context): array
    {
        $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
        if ($methodName !== '__set_state') {
            throw new MigrationParseException(\sprintf(
                'Unsupported static call ::%s() in %s',
                $methodName,
                $context,
            ));
        }
        if (! $call->class instanceof Node\Name) {
            throw new MigrationParseException(\sprintf(
                'Cannot resolve class name for __set_state in %s',
                $context,
            ));
        }
        $className = '\\' . ltrim($call->class->toString(), '\\');

        if (\count($call->args) !== 1) {
            throw new MigrationParseException(\sprintf(
                '__set_state takes exactly 1 argument in %s',
                $context,
            ));
        }
        $arg = $call->args[0];
        if (! $arg instanceof Node\Arg) {
            throw new MigrationParseException(\sprintf(
                'Spread argument to __set_state in %s',
                $context,
            ));
        }
        $payload = $this->extract($arg->value, $context);
        if (! \is_array($payload)) {
            throw new MigrationParseException(\sprintf(
                '__set_state argument must be an array in %s',
                $context,
            ));
        }

        return ['__class' => $className] + $payload;
    }
}

/**
 * Raised when {@see V1FileParser} encounters input it can't safely interpret.
 *
 * The migration runner converts these into entries on `ImportReport::$errors`
 * so a single bad file doesn't abort the whole import — but the bad file
 * itself contributes nothing to the migrated database.
 */
final class MigrationParseException extends \RuntimeException implements ImanagerException {}
