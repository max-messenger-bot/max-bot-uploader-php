<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Contract;

use MaxMessenger\Bot\Contract\Http\StreamInterface;
use MaxMessenger\Uploader\DTO\ResumableUploadInfo;
use MaxMessenger\Uploader\DTO\ResumableUploadStatus;

/**
 * Интерфейс клиента запросов для загрузки медиафайлов.
 *
 * Его можно реализовать самостоятельно.
 */
interface MaxUploaderHttpClientInterface
{
    /**
     * Получает с сервера информацию об уже загруженной части медиафайла.
     *
     * @param non-empty-string $url Адрес для получения информации о загрузке.
     * @return ResumableUploadInfo|ResumableUploadStatus Сведения о загруженной части медиафайла
     *     или действие при отсутствии этих сведений.
     */
    public function getResumableUploadInfo(string $url): ResumableUploadInfo|ResumableUploadStatus;

    /**
     * Отправляет готовую форму multipart/form-data.
     *
     * @template T of bool
     * @param non-empty-string $url Адрес загрузки.
     * @param StreamInterface $stream Поток с готовой формой multipart/form-data.
     * @param non-empty-string $contentType Тип содержимого формы с параметром boundary.
     * @param non-negative-int $timeout Максимальное время запроса в миллисекундах; `0` — без ограничения.
     * @param positive-int|null $lowSpeedLimit Минимальная скорость передачи в байтах в секунду или `null` без проверки.
     * @param positive-int|null $lowSpeedTime Допустимое время низкой скорости в секундах или `null` без проверки.
     * @param T $expectJsonResponse Запрашивать и разбирать ответ как JSON.
     * @return (T is true ? array : null) Разобранный JSON-ответ или `null` после проверки ответа без JSON.
     */
    public function postMultipartForm(
        string $url,
        StreamInterface $stream,
        string $contentType,
        int $timeout,
        ?int $lowSpeedLimit,
        ?int $lowSpeedTime,
        bool $expectJsonResponse,
    ): ?array;

    /**
     * Отправляет фрагмент медиафайла для возобновляемой загрузки.
     *
     * @template T of bool
     * @param non-empty-string $url Адрес загрузки.
     * @param StreamInterface $stream Поток с загружаемым фрагментом.
     * @param non-empty-string $postName Имя медиафайла при загрузке.
     * @param non-empty-string $contentType Тип содержимого медиафайла.
     * @param positive-int $size Общий размер содержимого в байтах.
     * @param non-negative-int $offset Смещение фрагмента от начала медиафайла в байтах.
     * @param non-negative-int $timeout Максимальное время запроса в миллисекундах; `0` — без ограничения.
     * @param positive-int|null $lowSpeedLimit Минимальная скорость передачи в байтах в секунду или `null` без проверки.
     * @param positive-int|null $lowSpeedTime Допустимое время низкой скорости в секундах или `null` без проверки.
     * @param T $expectJsonResponse Разбирать ответ как JSON.
     * @return (T is true ? array : ResumableUploadInfo) Разобранный JSON-ответ или диапазон загруженных байт.
     */
    public function postResumableUpload(
        string $url,
        StreamInterface $stream,
        string $postName,
        string $contentType,
        int $size,
        int $offset,
        int $timeout,
        ?int $lowSpeedLimit,
        ?int $lowSpeedTime,
        bool $expectJsonResponse,
    ): array|ResumableUploadInfo;
}
