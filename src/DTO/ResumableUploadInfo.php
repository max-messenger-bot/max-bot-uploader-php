<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\DTO;

/**
 * Хранит число переданных байт и общий размер медиафайла при возобновляемой загрузке.
 */
final class ResumableUploadInfo
{
    /**
     * @param non-negative-int $lastLoadedByte Последний загруженный байт.
     * @param non-negative-int $totalBytes Общий размер медиафайла в байтах.
     */
    public function __construct(
        public int $lastLoadedByte,
        public int $totalBytes,
    ) {}
}
