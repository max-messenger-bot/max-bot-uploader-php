<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\HttpClient\Stream;

use Closure;
use MaxMessenger\Uploader\Exception\StreamException;

use function fread;
use function fseek;

/**
 * Поток над ресурсом или его частью.
 */
final class ResourceStream extends BaseStream
{
    /**
     * @param resource $resource Ресурс потока.
     * @param bool $seekable Поддерживает ли ресурс перемотку.
     * @param non-negative-int $offset Смещение начала потока от начала данных.
     *     Для неперематываемого ресурса считается текущей позицией при создании потока.
     * @param positive-int $length Длина потока в байтах.
     * @param Closure(non-negative-int $bytesSent, non-negative-int $totalBytes): void|null $progressCallback
     *     Обработчик прогресса или `null`.
     */
    public function __construct(
        private readonly mixed $resource,
        private readonly bool $seekable,
        private readonly int $offset,
        int $length,
        ?Closure $progressCallback = null,
    ) {
        parent::__construct($length, $progressCallback);

        if ($this->seekable) {
            $this->seekTo(0);
        }
    }

    /**
     * @return non-negative-int Смещение начала потока от начала ресурса.
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * @return bool `true`, если ресурс поддерживает перемотку.
     */
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    protected function readBytes(int $length): string
    {
        $content = @fread($this->resource, $length);

        if ($content === false) {
            throw new StreamException('Failed to read the stream.');
        }

        return $content;
    }

    protected function seekTo(int $position): void
    {
        if (!$this->seekable) {
            throw new StreamException('Non-seekable stream cannot be repositioned.');
        }

        if (@fseek($this->resource, $this->offset + $position) !== 0) {
            throw new StreamException('Failed to seek the stream.');
        }
    }
}
