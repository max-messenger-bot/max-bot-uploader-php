<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Exception;

use MaxMessenger\Uploader\DTO\ResumableUploadInfo;

use function sprintf;

/**
 * Сервер подтвердил диапазон, не соответствующий переданному фрагменту медиафайла.
 */
final class UnexpectedUploadRangeException extends UploaderException
{
    /**
     * @param ResumableUploadInfo $uploadInfo Диапазон, подтверждённый сервером.
     * @param non-negative-int $expectedLastByte Последний байт отправленного фрагмента.
     * @param positive-int $expectedTotalBytes Размер загружаемого медиафайла.
     */
    public function __construct(ResumableUploadInfo $uploadInfo, int $expectedLastByte, int $expectedTotalBytes)
    {
        parent::__construct(sprintf(
            'Unexpected upload range: expected at least 0-%d/%d, received 0-%d/%d.',
            $expectedLastByte,
            $expectedTotalBytes,
            $uploadInfo->lastLoadedByte,
            $uploadInfo->totalBytes,
        ));
    }
}
