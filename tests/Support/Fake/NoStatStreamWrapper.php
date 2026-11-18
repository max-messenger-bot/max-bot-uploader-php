<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Support\Fake;

use function strlen;
use function substr;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * Перематываемая обёртка потока без `stream_stat()`: `fstat()` для неё возвращает `false`.
 *
 * Методы вызывает PHP через `stream_wrapper_register()`, а не код напрямую.
 *
 * @psalm-api
 */
final class NoStatStreamWrapper
{
    private const CONTENT = '0123456789';

    /**
     * @var resource|null
     */
    public $context;

    private int $position = 0;

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::CONTENT);
    }

    /**
     * @psalm-suppress UnusedParam
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $data = substr(self::CONTENT, $this->position, $count);
        $this->position += strlen($data);

        return $data;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen(self::CONTENT) + $offset,
            default => -1,
        };
        if ($position < 0) {
            return false;
        }
        $this->position = $position;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }
}
