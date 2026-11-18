<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Support\Fake;

use LogicException;
use MaxMessenger\Bot\Contract\Http\StreamInterface;

use function strlen;
use function substr;

use const SEEK_SET;

/**
 * Поток над строкой с настраиваемой поддержкой перемотки.
 */
final class FakeStream implements StreamInterface
{
    /**
     * @var non-negative-int
     */
    private int $position = 0;

    public function __construct(
        private readonly string $content,
        private readonly bool $seekable = true,
    ) {}

    public function eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    public function getContents(): string
    {
        $content = substr($this->content, $this->position);
        $this->position = strlen($this->content);

        return $content;
    }

    public function getSize(): int
    {
        return strlen($this->content) ?: throw new LogicException('Stream must not be empty.');
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function read(int $length): string
    {
        $content = substr($this->content, $this->position, $length);
        $this->position += strlen($content);

        return $content;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->seekable) {
            throw new LogicException('Stream is not seekable.');
        }
        if ($offset < 0) {
            throw new LogicException('Stream position must not be negative.');
        }

        $this->position = $offset;
    }

    public function tell(): int
    {
        return $this->position;
    }
}
