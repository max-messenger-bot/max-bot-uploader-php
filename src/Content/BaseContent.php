<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Content;

use MaxMessenger\Uploader\Contract\ContentInterface;
use MaxMessenger\Uploader\Exception\StreamException;

use function basename;
use function explode;
use function str_contains;

/**
 * Предоставляет общие свойства содержимого для загрузки медиафайла.
 */
abstract class BaseContent implements ContentInterface
{
    /**
     * Имя медиафайла, используемое при загрузке.
     *
     * @var non-empty-string
     */
    public readonly string $postName;
    /**
     * Размер содержимого в байтах.
     *
     * @var positive-int
     */
    public readonly int $size;

    /**
     * Проверяет размер содержимого и извлекает имя медиафайла.
     *
     * @param string $postName Имя, путь или ссылка, из которых извлекается имя медиафайла.
     * @param int $size Размер содержимого в байтах; должен быть больше нуля.
     * @throws StreamException Если размер не положительный или не удалось извлечь имя медиафайла.
     */
    public function __construct(string $postName, int $size)
    {
        if ($size < 1) {
            throw new StreamException('Content cannot be empty.');
        }

        $this->size = $size;
        $this->postName = $this->extractPostName($postName);
    }

    /**
     * @return non-empty-string Тип содержимого для заголовка `Content-Type`.
     */
    public function getContentType(): string
    {
        return 'application/octet-stream';
    }

    /**
     * @return non-empty-string Имя медиафайла, используемое при загрузке.
     */
    public function getPostName(): string
    {
        return $this->postName;
    }

    /**
     * @return positive-int Размер содержимого в байтах.
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * @return non-empty-string
     */
    private function extractPostName(string $postName): string
    {
        // У ссылки отбрасываем query; в имени локального файла `?` допустим.
        if (str_contains($postName, '://')) {
            $postName = explode('?', $postName, 2)[0];
        }
        $postName = basename($postName);
        if ($postName === '') {
            throw new StreamException('Failed to extract post name.');
        }

        return $postName;
    }
}
