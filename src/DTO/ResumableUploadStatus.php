<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\DTO;

/**
 * Действие при отсутствии сведений о загруженной части медиафайла.
 *
 * Возможные значения:
 * - `ContinueFromLastOffset` — продолжить с последней успешно загруженной позиции.
 * - `StartFromBeginning` — начать загрузку с начала по текущему адресу.
 * - `UploadEndpointUnavailable` — получить другой адрес загрузки.
 */
enum ResumableUploadStatus
{
    case ContinueFromLastOffset;
    case StartFromBeginning;
    case UploadEndpointUnavailable;
}
