<?php

declare(strict_types=1);

namespace Imanager\Files;

use Imanager\Exception\ImanagerException;

/**
 * Raised by {@see UploadedFile} / {@see UploadHandler} when an upload
 * fails policy checks (size, extension, mime, PHP `UPLOAD_ERR_*`) or
 * arrives via something other than a genuine HTTP POST.
 */
final class UploadException extends \RuntimeException implements ImanagerException {}
