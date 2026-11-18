<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Exception;

/**
 * Заданный адрес загрузки не подходит для продолжения передачи медиафайла.
 */
final class UploadEndpointException extends UploaderException
{
    public function __construct()
    {
        parent::__construct('The provided upload endpoint cannot be used for this upload.');
    }
}
