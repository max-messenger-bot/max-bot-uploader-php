<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader;

use MaxMessenger\Bot\HttpClient\Exception\TimeoutException;
use MaxMessenger\Bot\Model\Enum\UploadType;
use MaxMessenger\Bot\Model\Response\UploadEndpoint;
use MaxMessenger\Uploader\Contract\ContentInterface;
use MaxMessenger\Uploader\DTO\ResumableUploadInfo;
use MaxMessenger\Uploader\DTO\ResumableUploadStatus;
use MaxMessenger\Uploader\DTO\UploadEventType;
use MaxMessenger\Uploader\Exception\UnexpectedUploadRangeException;
use MaxMessenger\Uploader\Exception\UploadEndpointException;
use RuntimeException;

use function intdiv;
use function max;
use function min;

/**
 * Загружает медиафайлы в МАКС по фрагментам с возможностью возобновления передачи.
 */
final class MaxUploader extends BaseUploader
{
    /**
     * @var positive-int Максимальная длина одного фрагмента в байтах.
     */
    public int $fragmentLength = PHP_INT_MAX;
    /**
     * Разрешает получать сведения об уже загруженной части медиафайла.
     *
     * Если `true`, смещение для продолжения определяется по ответу сервера.
     * Если `false`, используется сохранённое смещение последнего фрагмента.
     */
    public bool $smartResume = true;
    /**
     * Начинать загрузку медиафайла с начала без запроса его состояния.
     *
     * Действует только при включённом {@see self::$smartResume} и заданном
     * {@see BaseUploader::$uploadEndpoint} (задаётся через {@see self::setUploadEndpoint()}).
     *
     * При каждой загрузке значение сбрасывается в `true`. Для возобновления перед каждой загрузкой
     * установите `false` напрямую или передайте его вторым аргументом `setUploadEndpoint()`.
     */
    public bool $startFromBeginning = true;
    /**
     * Начать следующую попытку с нуля без запроса состояния загрузки.
     */
    private bool $forceStartFromBeginning = false;
    /**
     * @var non-negative-int
     */
    private int $lastFragmentOffset = 0;

    /**
     * @param positive-int $fragmentLength Максимальная длина одного фрагмента в байтах.
     * @return $this
     */
    public function setFragmentLength(int $fragmentLength): self
    {
        $this->fragmentLength = $fragmentLength;

        return $this;
    }

    /**
     * @param bool $smartResume Разрешать ли получение сведений об уже загруженной части медиафайла.
     * @return $this
     */
    public function setSmartResume(bool $smartResume): self
    {
        $this->smartResume = $smartResume;

        return $this;
    }

    /**
     * @param UploadEndpoint|null $uploadEndpoint Адрес загрузки или `null` для автоматического получения адреса.
     * @param bool $startFromBeginning При включённом {@see self::$smartResume} начать загрузку с начала без
     *     запроса её состояния.
     * @return $this
     */
    public function setUploadEndpoint(?UploadEndpoint $uploadEndpoint, bool $startFromBeginning = true): static
    {
        parent::setUploadEndpoint($uploadEndpoint);

        $this->startFromBeginning = $startFromBeginning;

        return $this;
    }

    /**
     * @template T of bool
     * @param T $json
     * @return (T is true ? array : non-empty-string)
     */
    protected function uploadAttempt(
        ContentInterface $content,
        UploadType $type,
        bool $endpointProvided,
        bool $json,
        bool &$canRetry,
    ): array|string {
        $contentSize = $content->getSize();

        if ($this->lastUploadAttempts === 1 && !$content->isSeekable()) {
            $this->startFromBeginning = true;
        }

        $this->resolveUploadEndpoint($type, $endpointProvided, $contentSize);

        /** @var (T is true ? null : non-empty-string) $token */
        if (!$json) {
            $token = self::getUploadToken($this->lastUploadEndpoint);

            if ($this->lastFragmentOffset === $contentSize) {
                return $token;
            }
        }

        // Загрузка медиафайла должна укладываться в 1000 запросов.
        // Используем размер / 900 как нижнюю границу длины фрагмента, чтобы оставить запас.
        $maxFragmentLength = max($this->fragmentLength, intdiv($contentSize, 900), 65536);
        // Сервер изображений требует границы фрагментов, кратные 32768 байтам: округляем вверх.
        if ($maxFragmentLength < $contentSize) {
            $maxFragmentLength = (intdiv($maxFragmentLength - 1, 32768) + 1) * 32768;
        }

        if ($this->lastFragmentOffset >= $contentSize) {
            $this->lastFragmentOffset = $contentSize - 1;
        }

        do {
            $this->eventCallback?->__invoke(UploadEventType::SendFragment);

            /** @var positive-int $fragmentLength */
            $fragmentLength = min($contentSize - $this->lastFragmentOffset, $maxFragmentLength);
            $progressCallback = $this->makeProgressCallback($this->lastFragmentOffset, $contentSize);
            $stream = $content->getStream($this->lastFragmentOffset, $fragmentLength, $progressCallback);

            try {
                $data = $this->uploadClient->postResumableUpload(
                    $this->lastUploadEndpoint->getUrl(),
                    $stream,
                    $content->getPostName(),
                    $content->getContentType(),
                    $contentSize,
                    $this->lastFragmentOffset,
                    $this->timeout,
                    $this->lowSpeedLimit,
                    $this->lowSpeedTime,
                    $json,
                );
                /** @var (T is true ? array : ResumableUploadInfo) $data Psalm теряет условный тип при передаче T. */
            } catch (RuntimeException $e) {
                $this->forceStartFromBeginning = !$e instanceof TimeoutException;
                $canRetry = $stream->isSeekable()
                    || ($stream->tell() === 0 && (!$this->forceStartFromBeginning || $this->lastFragmentOffset === 0));

                throw $e;
            }

            if (!$json) {
                /** @var ResumableUploadInfo $data Psalm не сужает условный тип в ветке false. */
                $pos = $this->lastFragmentOffset + $fragmentLength - 1;
                if ($data->totalBytes !== $contentSize || $data->lastLoadedByte < $pos) {
                    throw new UnexpectedUploadRangeException($data, $pos, $contentSize);
                }
            }

            $this->lastFragmentOffset += $fragmentLength;
        } while ($this->lastFragmentOffset < $contentSize);

        /** @var (T is true ? array : ResumableUploadInfo) $data Psalm теряет условный тип после проверки диапазона. */
        return $json ? $data : $token;
    }

    /**
     * @param non-empty-string $url
     * @param non-negative-int $contentSize
     */
    private function refreshUploadState(string $url, int $contentSize): bool
    {
        $this->eventCallback?->__invoke(UploadEventType::FileInfoRequest);

        $uploadInfo = $this->uploadClient->getResumableUploadInfo($url);

        if ($uploadInfo instanceof ResumableUploadInfo) {
            if ($uploadInfo->totalBytes !== $contentSize) {
                return false;
            }

            $this->lastFragmentOffset = $uploadInfo->lastLoadedByte + 1;

            return true;
        }

        if ($uploadInfo === ResumableUploadStatus::StartFromBeginning) {
            $this->lastFragmentOffset = 0;
        }

        return $uploadInfo !== ResumableUploadStatus::UploadEndpointUnavailable;
    }

    /**
     * @param non-negative-int $contentSize
     * @psalm-assert !null $this->lastUploadEndpoint
     */
    private function resolveUploadEndpoint(UploadType $type, bool $endpointProvided, int $contentSize): void
    {
        $firstAttempt = $this->lastUploadAttempts === 1;

        if ($firstAttempt || $this->forceStartFromBeginning || $this->lastUploadEndpoint === null) {
            $this->lastFragmentOffset = 0;
        }

        $checkUploadInfo = $this->smartResume
            && $this->lastUploadEndpoint !== null
            && ($firstAttempt ? !$this->startFromBeginning : !$this->forceStartFromBeginning);

        $this->startFromBeginning = true;
        $this->forceStartFromBeginning = false;

        if ($checkUploadInfo && !$this->refreshUploadState($this->lastUploadEndpoint->getUrl(), $contentSize)) {
            if ($endpointProvided) {
                throw new UploadEndpointException();
            }

            $this->lastUploadEndpoint = null;
            $this->lastFragmentOffset = 0;
        }

        if (!$firstAttempt || $checkUploadInfo) {
            $this->eventCallback?->__invoke(
                $this->lastFragmentOffset > 0 ? UploadEventType::Resume : UploadEventType::Reset,
            );
        }

        $this->lastUploadEndpoint ??= $this->getUploadEndpoint($type);
    }
}
