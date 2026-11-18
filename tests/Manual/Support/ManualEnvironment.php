<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Manual\Support;

use Throwable;

use function get_debug_type;
use function set_exception_handler;
use function sprintf;

/**
 * Общие настройки ручных сценариев.
 */
final class ManualEnvironment
{
    private static bool $exceptionHandlerInitialized = false;

    /**
     * Устанавливает единый обработчик неперехваченных ошибок ручных тестов.
     */
    public static function initializeExceptionHandler(): void
    {
        if (self::$exceptionHandlerInitialized) {
            return;
        }

        set_exception_handler(static function (Throwable $exception): void {
            Console::console()->error(sprintf(
                '%s: %s (%s:%d)',
                get_debug_type($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));
            exit(1);
        });
        self::$exceptionHandlerInitialized = true;
    }
}
