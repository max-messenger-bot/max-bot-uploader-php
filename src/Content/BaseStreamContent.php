<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Content;

use Closure;
use MaxMessenger\Bot\Contract\Http\StreamInterface;
use MaxMessenger\Uploader\Exception\StreamException;
use MaxMessenger\Uploader\Exception\StreamLogicException;
use MaxMessenger\Uploader\HttpClient\Stream\ResourceStream;

use function fseek;
use function fstat;
use function ftell;
use function get_resource_type;
use function is_int;
use function is_resource;
use function str_contains;
use function str_starts_with;
use function stream_get_meta_data;

/**
 * Предоставляет содержимое ресурса потока для загрузки медиафайла.
 */
abstract class BaseStreamContent extends BaseContent
{
    private readonly bool $seekable;
    /**
     * Последний созданный поток; заполняется только для неперематываемых потоков.
     */
    private ?ResourceStream $stream = null;

    /**
     * Создаёт источник из ресурса потока и извлекает имя медиафайла.
     *
     * @param resource $resource Ресурс потока, открытый для чтения.
     * @param string $postName Имя или путь, из которого извлекается имя медиафайла.
     * @param positive-int|null $size Размер потока в байтах; если не указан, вычисляется из ресурса.
     * @throws StreamException Если поток не открыт для чтения, пустой или не удалось определить размер.
     */
    public function __construct(
        public readonly mixed $resource,
        string $postName,
        ?int $size = null,
    ) {
        /** @psalm-suppress DocblockTypeContradiction */
        if (!is_resource($this->resource) || get_resource_type($this->resource) !== 'stream') {
            throw new StreamLogicException('A stream resource is expected.');
        }

        $meta = @stream_get_meta_data($this->resource);
        $mode = $meta['mode'];
        if (!str_starts_with($mode, 'r') && !str_contains($mode, '+')) {
            throw new StreamException('Stream must be readable.');
        }
        $this->seekable = $meta['seekable'] ?? false;

        parent::__construct($postName, $size ?? $this->extractResourceSize());
    }

    /**
     * Возвращает поток для фрагмента содержимого.
     *
     * @param non-negative-int $offset Смещение фрагмента от начала потока.
     * @param positive-int $length Длина фрагмента в байтах.
     * @param Closure(non-negative-int $bytesSent, non-negative-int $totalBytes): void|null $progressCallback
     *     Обработчик прогресса или `null`.
     * @return StreamInterface Поток с указанным фрагментом.
     */
    public function getStream(int $offset, int $length, ?Closure $progressCallback = null): StreamInterface
    {
        if ($this->stream !== null && $this->stream->getOffset() + $this->stream->tell() !== $offset) {
            throw new StreamException('Non-seekable stream is not at the requested offset.');
        }

        $stream = new ResourceStream($this->resource, $this->seekable, $offset, $length, $progressCallback);
        if (!$this->seekable) {
            $this->stream = $stream;
        }

        return $stream;
    }

    /**
     * @return bool Поддерживает ли источник перемотку.
     */
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    protected function extractResourceSize(): int
    {
        // fstat() reports 0 for pipes and sockets, so it is not trusted for non-seekable streams.
        if (!$this->seekable) {
            throw new StreamLogicException('A non-seekable stream requires an explicit $size.');
        }

        $stat = @fstat($this->resource);
        if ($stat !== false && is_int($size = $stat['size'] ?? null)) {
            return $size;
        }

        if (@fseek($this->resource, 0, SEEK_END) !== 0) {
            throw new StreamException('Failed to get stream size (fseek).');
        }
        $size = @ftell($this->resource);
        if ($size === false) {
            throw new StreamException('Failed to get stream size (ftell).');
        }

        return $size;
    }
}
