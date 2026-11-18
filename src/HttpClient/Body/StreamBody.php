<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\HttpClient\Body;

use MaxMessenger\Bot\Contract\Http\BodyInterface;
use MaxMessenger\Bot\Contract\Http\StreamInterface;

/**
 * Тело запроса, которое клиент читает из потока.
 */
final readonly class StreamBody implements BodyInterface
{
    /**
     * @param StreamInterface $stream Поток с содержимым тела запроса.
     * @param non-empty-string $contentType Значение заголовка `Content-Type`.
     */
    public function __construct(
        private StreamInterface $stream,
        private string $contentType,
    ) {}

    /**
     * @return StreamInterface Поток с содержимым тела запроса.
     */
    public function getBody(): StreamInterface
    {
        return $this->stream;
    }

    /**
     * @return non-empty-string Значение заголовка `Content-Type`.
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }
}
