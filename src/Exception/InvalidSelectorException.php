<?php

declare(strict_types=1);

namespace Imanager\Exception;

/**
 * Thrown when a selector string passed to {@see \Imanager\Query\SelectorParser}
 * cannot be parsed into a {@see \Imanager\Query\Query}.
 *
 * Logic-level error: the selector is supposed to be authored by code (or
 * sanitized at the input boundary) before reaching the parser. If it does
 * come straight from a URL, the request layer is responsible for catching
 * and translating to a 400-equivalent response.
 */
final class InvalidSelectorException extends \InvalidArgumentException implements ImanagerException {}
