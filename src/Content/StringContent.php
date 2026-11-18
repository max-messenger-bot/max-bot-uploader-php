<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Content;

use Closure;
use MaxMessenger\Bot\Contract\Http\StreamInterface;
use MaxMessenger\Uploader\Exception\StreamException;
use MaxMessenger\Uploader\HttpClient\Stream\StringStream;

use function strlen;

/**
 * Предоставляет содержимое строки для загрузки как медиафайла.
 */
final class StringContent extends BaseContent
{
    /**
     * Создаёт источник из строки и извлекает имя медиафайла.
     *
     * @param non-empty-string $content Строка содержимого.
     * @param string $postName Имя или путь, из которого извлекается имя медиафайла.
     * @throws StreamException Если содержимое пустое.
     */
    public function __construct(
        public readonly string $content,
        string $postName,
    ) {
        parent::__construct($postName, strlen($this->content));
    }

    /**
     * Возвращает поток для фрагмента строки.
     *
     * @param non-negative-int $offset Смещение фрагмента от начала строки.
     * @param positive-int $length Длина фрагмента в байтах.
     * @param Closure(non-negative-int $bytesSent, non-negative-int $totalBytes): void|null $progressCallback
     *     Обработчик прогресса или `null`.
     * @return StreamInterface Поток с указанным фрагментом.
     */
    public function getStream(int $offset, int $length, ?Closure $progressCallback = null): StreamInterface
    {
        return new StringStream($this->content, $offset, $length, $progressCallback);
    }

    /**
     * @return true Строковое содержимое допускает перемотку.
     */
    public function isSeekable(): true
    {
        return true;
    }
}
