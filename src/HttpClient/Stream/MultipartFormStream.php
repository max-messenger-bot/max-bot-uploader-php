<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\HttpClient\Stream;

use Closure;
use MaxMessenger\Bot\Contract\Http\StreamInterface;

use function bin2hex;
use function random_bytes;
use function sprintf;
use function str_replace;
use function strlen;

/**
 * Поток формы `multipart/form-data` с единственным медиафайлом.
 *
 * Содержимое медиафайла читается между заголовком части и замыкающей границей.
 */
final class MultipartFormStream extends CompositeStream
{
    /**
     * @var non-empty-string
     */
    private readonly string $boundary;

    /**
     * @param StreamInterface $stream Поток с содержимым медиафайла.
     * @param non-empty-string $postName Имя медиафайла, используемое при загрузке.
     * @param non-empty-string $contentType Тип содержимого медиафайла в форме.
     * @param Closure(non-negative-int $bytesSent, non-negative-int $totalBytes): void|null $progressCallback
     *     Вызывается по мере отправки формы.
     */
    public function __construct(
        StreamInterface $stream,
        string $postName,
        string $contentType,
        ?Closure $progressCallback = null,
    ) {
        $this->boundary = bin2hex(random_bytes(16));

        $header = sprintf(
            "--%s\r\nContent-Disposition: form-data; name=\"data\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n",
            $this->boundary,
            self::escape($postName),
            $contentType,
        );
        $footer = sprintf("\r\n--%s--\r\n", $this->boundary);

        $streams = [
            new StringStream($header, 0, strlen($header)),
            $stream,
            new StringStream($footer, 0, strlen($footer)),
        ];

        parent::__construct($streams, $progressCallback);
    }

    /**
     * @return non-empty-string Значение заголовка `Content-Type` для формы.
     */
    public function getContentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    private static function escape(string $value): string
    {
        return str_replace(["\r", "\n", '"'], '', $value);
    }
}
