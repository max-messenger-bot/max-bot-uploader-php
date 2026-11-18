<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\HttpClient\Stream;

use Closure;
use MaxMessenger\Uploader\Exception\StreamLogicException;

use function strlen;
use function substr;

/**
 * Поток над строкой или её частью.
 */
final class StringStream extends BaseStream
{
    /**
     * @param string $content Строка с данными.
     * @param non-negative-int $offset Смещение начала потока в строке.
     * @param positive-int $length Длина потока в байтах.
     * @param Closure(non-negative-int $bytesSent, non-negative-int $totalBytes): void|null $progressCallback Обработчик прогресса или `null`.
     */
    public function __construct(
        private readonly string $content,
        private readonly int $offset,
        int $length,
        ?Closure $progressCallback = null,
    ) {
        parent::__construct($length, $progressCallback);

        /** @psalm-suppress DocblockTypeContradiction */
        if ($this->offset < 0 || $this->offset > strlen($this->content) - $length) {
            throw new StreamLogicException('Requested range is outside the string.');
        }
    }

    /**
     * @return string Оставшиеся данные потока от текущей позиции.
     */
    public function getContents(): string
    {
        return $this->read($this->getSize());
    }

    /**
     * @return true Строку можно читать повторно с любого смещения.
     */
    public function isSeekable(): true
    {
        return true;
    }

    protected function readBytes(int $length): string
    {
        return substr($this->content, $this->offset + $this->tell(), $length);
    }

    protected function seekTo(int $position): void {}
}
