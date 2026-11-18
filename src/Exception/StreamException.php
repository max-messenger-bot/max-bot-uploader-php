<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Exception;

/**
 * Исключение при работе с потоком.
 *
 * Возникает при ошибке работы с потоками во время загрузки медиафайла.
 */
final class StreamException extends UploaderException {}
