<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\HttpClient\Stream;

use Closure;
use MaxMessenger\Bot\Contract\Http\StreamInterface;

use function strlen;

/**
 * Несколько потоков, читаемых подряд как один.
 */
class CompositeStream extends BaseStream
{
    /**
     * @var non-negative-int Номер потока, из которого идёт чтение.
     */
    private int $index = 0;
    private readonly bool $seekable;

    /**
     * @param non-empty-list<StreamInterface> $streams Потоки в порядке чтения.
     * @param Closure(non-negative-int $bytesSent, non-negative-int $totalBytes): void|null $progressCallback
     *     Вызывается после каждой прочитанной порции.
     */
    public function __construct(
        private readonly array $streams,
        ?Closure $progressCallback = null,
    ) {
        $size = 0;
        $seekable = true;

        foreach ($this->streams as $stream) {
            $size += $stream->getSize();
            $seekable = $seekable && $stream->isSeekable();
        }

        $this->seekable = $seekable;

        parent::__construct($size, $progressCallback);
    }

    /**
     * @return bool `true`, если все объединённые потоки поддерживают перемотку.
     */
    final public function isSeekable(): bool
    {
        return $this->seekable;
    }

    protected function readBytes(int $length): string
    {
        $content = '';
        $bytesLeft = $length;

        while ($bytesLeft > 0 && isset($this->streams[$this->index])) {
            $chunk = $this->streams[$this->index]->read($bytesLeft);

            if ($chunk === '') {
                $this->index++;

                continue;
            }

            $content .= $chunk;
            $bytesLeft -= strlen($chunk);
        }

        return $content;
    }

    protected function seekTo(int $position): void
    {
        $bytesLeft = $position;
        $this->index = 0;

        foreach ($this->streams as $index => $stream) {
            $size = $stream->getSize();

            if ($bytesLeft >= $size) {
                $bytesLeft -= $size;
                $this->index = $index + 1;

                continue;
            }

            $stream->seek($bytesLeft);
            $bytesLeft = 0;
        }
    }
}
