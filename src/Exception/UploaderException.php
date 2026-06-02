<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Exception;

use MaxMessenger\Bot\Exception\MaxApiException;

/**
 * Base class for uploader exceptions.
 *
 * Abstract exception for errors that occur during the file upload process.
 */
abstract class UploaderException extends MaxApiException {}
