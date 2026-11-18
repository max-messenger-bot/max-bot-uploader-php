<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader;

use MaxMessenger\Bot\Model\Enum\UploadType;
use MaxMessenger\Uploader\Contract\ContentInterface;
use MaxMessenger\Uploader\DTO\UploadEventType;
use MaxMessenger\Uploader\HttpClient\Stream\MultipartFormStream;
use RuntimeException;

/**
 * Загружает медиафайлы в МАКС одним запросом с формой `multipart/form-data`.
 */
final class MaxMultipartFormUploader extends BaseUploader
{
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

        $this->lastUploadEndpoint ??= $this->getUploadEndpoint($type);

        /** @var (T is true ? null : non-empty-string) $token */
        if (!$json) {
            $token = self::getUploadToken($this->lastUploadEndpoint);
        }

        $this->eventCallback?->__invoke(UploadEventType::Send);

        $stream = $content->getStream(0, $contentSize);
        $multipartStream = new MultipartFormStream(
            $stream,
            $content->getPostName(),
            $content->getContentType(),
            $this->makeProgressCallback(),
        );

        try {
            $data = $this->uploadClient->postMultipartForm(
                $this->lastUploadEndpoint->getUrl(),
                $multipartStream,
                $multipartStream->getContentType(),
                $this->timeout,
                $this->lowSpeedLimit,
                $this->lowSpeedTime,
                $json,
            );
            /** @var (T is true ? array : null) $data Psalm теряет условный тип при передаче T. */
        } catch (RuntimeException $e) {
            $canRetry = $stream->isSeekable() || $stream->tell() === 0;

            throw $e;
        }

        return $json ? $data : $token;
    }
}
