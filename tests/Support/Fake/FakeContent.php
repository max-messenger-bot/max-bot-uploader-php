<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Support\Fake;

use Closure;
use MaxMessenger\Bot\Contract\Http\StreamInterface;
use MaxMessenger\Uploader\Contract\ContentInterface;

use function strlen;
use function substr;

/**
 * Содержимое для загрузки, отдающее потоки {@see FakeStream} с настраиваемой поддержкой перемотки.
 */
final readonly class FakeContent implements ContentInterface
{
    /**
     * @param non-empty-string $content
     */
    public function __construct(
        private string $content,
        private bool $seekable,
    ) {}

    public function getContentType(): string
    {
        return 'application/octet-stream';
    }

    public function getPostName(): string
    {
        return 'fake.bin';
    }

    public function getSize(): int
    {
        return strlen($this->content);
    }

    public function getStream(int $offset, int $length, ?Closure $progressCallback = null): StreamInterface
    {
        return new FakeStream(substr($this->content, $offset, $length), $this->seekable);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }
}
