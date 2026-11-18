<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\HttpClient\Stream;

use Closure;
use MaxMessenger\Bot\Contract\Http\StreamInterface;
use MaxMessenger\Uploader\Exception\StreamException;
use MaxMessenger\Uploader\Exception\StreamLogicException;

use function min;
use function strlen;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * Базовая реализация потока данных.
 *
 * Управляет чтением и позицией в пределах длины, указанной при создании потока.
 * Источник данных и перемещение в нём определяют наследники.
 */
abstract class BaseStream implements StreamInterface
{
    /**
     * @var non-negative-int
     */
    private int $cursor = 0;

    /**
     * @param positive-int $length Длина потока в байтах.
     * @param Closure(non-negative-int $bytesSent, non-negative-int $totalBytes): void|null $progressCallback
     *     Вызывается после каждой прочитанной порции.
     */
    public function __construct(
        private readonly int $length,
        private readonly ?Closure $progressCallback = null,
    ) {
        /** @psalm-suppress DocblockTypeContradiction */
        if ($this->length < 1) {
            throw new StreamLogicException('Stream size must be positive.');
        }
    }

    /**
     * @return bool `true`, если достигнут конец потока.
     */
    final public function eof(): bool
    {
        return $this->cursor >= $this->length;
    }

    /**
     * @return string Оставшиеся данные потока от текущей позиции.
     */
    public function getContents(): string
    {
        $content = '';
        while ($this->cursor < $this->length) {
            $content .= $this->read(262144);
        }

        return $content;
    }

    /**
     * @return positive-int Длина потока в байтах.
     */
    final public function getSize(): int
    {
        return $this->length;
    }

    /**
     * Читает очередную порцию потока с текущей позиции.
     *
     * @param non-negative-int $length Максимальное число байт для чтения.
     * @return string Прочитанные данные.
     */
    final public function read(int $length): string
    {
        $bytesToRead = min($length, $this->length - $this->cursor);

        if ($bytesToRead < 1) {
            return '';
        }

        $content = $this->readBytes($bytesToRead);

        $bytesRead = strlen($content);
        $this->cursor += $bytesRead;

        if ($bytesRead === 0) {
            throw new StreamException('Not enough data to read.');
        }
        if ($bytesRead > $bytesToRead) {
            throw new StreamException('Read more data than requested.');
        }

        $this->progressCallback?->__invoke($this->cursor, $this->length);

        return $content;
    }

    /**
     * Перемещает текущую позицию внутри потока.
     *
     * @param int $offset Смещение относительно начала, текущей позиции или конца потока.
     * @param int $whence Точка отсчёта: `SEEK_SET`, `SEEK_CUR` или `SEEK_END`.
     */
    final public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $offset = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->cursor + $offset,
            SEEK_END => $this->length + $offset,
            default => throw new StreamLogicException('Unknown seek origin.'),
        };

        if ($offset < 0 || $offset > $this->length) {
            throw new StreamLogicException('Position is outside the stream.');
        }

        if ($offset === $this->cursor) {
            return;
        }

        $this->seekTo($offset);

        $this->cursor = $offset;
    }

    /**
     * @return non-negative-int Текущая позиция от начала потока.
     */
    final public function tell(): int
    {
        return $this->cursor;
    }

    /**
     * Читает очередную порцию данных с текущей позиции источника.
     *
     * @param positive-int $length Сколько байт прочитать.
     * @return string Прочитанные данные.
     * @throws StreamException Если прочитать данные не удалось.
     */
    abstract protected function readBytes(int $length): string;

    /**
     * Перемещает источник на заданную позицию потока.
     *
     * @param non-negative-int $position Позиция от начала потока.
     * @throws StreamException Если перемотка не удалась.
     */
    abstract protected function seekTo(int $position): void;
}
