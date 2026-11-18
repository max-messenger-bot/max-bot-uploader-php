<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\DTO;

/**
 * События загрузчиков.
 *
 * Возможные значения:
 * - `Error` — Генерируется при неожиданном HTTP-коде ответа на передачу медиафайла.
 * - `FileInfoRequest` — Генерируется перед запросом информации о состоянии загружаемого медиафайла.
 * - `Reset` — Генерируется при перезапуске загрузки с начала.
 * - `Resume` — Генерируется при возобновлении загрузки.
 * - `Send` — Генерируется перед отправкой всего содержимого медиафайла.
 * - `SendFragment` — Генерируется перед началом загрузки очередного фрагмента.
 * - `Timeout` — Генерируется при получении исключения с таймаутом.
 * - `UrlRequest` — Генерируется перед запросом ссылки для загрузки.
 */
enum UploadEventType
{
    case Error;
    case FileInfoRequest;
    case Reset;
    case Resume;
    case Send;
    case SendFragment;
    case Timeout;
    case UrlRequest;
}
