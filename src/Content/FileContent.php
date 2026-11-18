<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Content;

use MaxMessenger\Uploader\Exception\StreamException;

use function fopen;

/**
 * Предоставляет для загрузки содержимое источника, открываемого через `fopen()`.
 */
final class FileContent extends BaseStreamContent
{
    /**
     * Открывает источник для чтения и извлекает имя медиафайла.
     *
     * @param non-empty-string $fileName Путь или адрес источника, доступного через `fopen()`.
     * @param string|null $postName Имя или путь, из которого извлекается имя медиафайла.
     *     Если не указано, используется `$fileName`.
     * @param positive-int|null $size Размер содержимого в байтах; если не указан, вычисляется из ресурса.
     * @throws StreamException Если источник не удалось открыть, он пустой или не удалось определить размер.
     */
    public function __construct(
        public readonly string $fileName,
        ?string $postName = null,
        ?int $size = null,
    ) {
        $resource = @fopen($this->fileName, 'rb');

        if (!$resource) {
            throw new StreamException('Unable to open file: ' . $this->fileName);
        }

        parent::__construct($resource, $postName ?? $this->fileName, $size);
    }
}
