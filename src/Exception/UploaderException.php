<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Exception;

use MaxMessenger\Bot\Exception\MaxApiException;

/**
 * Базовый класс исключений загрузчика.
 *
 * Абстрактное исключение для ошибок, возникающих при загрузке медиафайла.
 */
abstract class UploaderException extends MaxApiException {}
