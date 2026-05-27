<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Exception;

use MaxMessenger\Bot\Exception\MaxApiLogicException;

/**
 * Stream logic exception.
 *
 * Thrown when a logic error occurs while working with streams during the upload process.
 */
final class StreamLogicException extends MaxApiLogicException
{
}
