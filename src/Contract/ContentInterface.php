<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Contract;

use Closure;
use MaxMessenger\Bot\Contract\Http\StreamInterface;

/**
 * Интерфейс для реализации провайдеров контента.
 */
interface ContentInterface
{
    /**
     * @return non-empty-string Тип содержимого для заголовка `Content-Type`.
     */
    public function getContentType(): string;

    /**
     * @return non-empty-string Имя медиафайла.
     */
    public function getPostName(): string;

    /**
     * @return positive-int Общий размер содержимого в байтах.
     */
    public function getSize(): int;

    /**
     * Возвращает поток для заданного аргументами $offset / $length фрагмента.
     *
     * Если поток поддерживает событие Progress, он должен вызывать замыкание $progressCallback.
     *
     * @param non-negative-int $offset Смещение фрагмента от начала содержимого.
     * @param positive-int $length Длина фрагмента в байтах.
     * @param Closure(non-negative-int $bytesSent, non-negative-int $totalBytes): void|null $progressCallback
     *     Обработчик прогресса или `null`.
     * @return StreamInterface Поток с указанным фрагментом.
     */
    public function getStream(int $offset, int $length, ?Closure $progressCallback = null): StreamInterface;

    /**
     * @return bool Поддерживает ли источник перемотку.
     */
    public function isSeekable(): bool;
}
