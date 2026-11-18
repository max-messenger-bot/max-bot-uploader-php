<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Exception;

use MaxMessenger\Bot\Exception\MaxApiLogicException;

/**
 * Исключение при нарушении логики работы с потоком.
 *
 * Возникает при нарушении логики работы с потоками во время загрузки медиафайла.
 */
final class StreamLogicException extends MaxApiLogicException {}
