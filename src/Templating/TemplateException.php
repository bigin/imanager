<?php

declare(strict_types=1);

namespace Imanager\Templating;

use Imanager\Exception\ImanagerException;

/**
 * Raised by {@see TemplateRenderer} on I/O errors when reading a template
 * file from disk. Substitution itself never throws — unmatched placeholders
 * are stripped silently.
 */
final class TemplateException extends \RuntimeException implements ImanagerException {}
