<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\HttpClient;

use JsonException;
use MaxMessenger\Bot\Contract\Http\HttpClientInterface;
use MaxMessenger\Bot\Contract\Http\HttpRequestInterface;
use MaxMessenger\Bot\Contract\Http\HttpResponseInterface;
use MaxMessenger\Bot\Contract\Http\StreamInterface;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\Http\HttpException;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\JsonDecodeException;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\UnexpectedContentTypeException;
use MaxMessenger\Bot\HttpClient\HttpRequest;
use MaxMessenger\Bot\Model\Response\Error;
use MaxMessenger\Uploader\Contract\MaxUploaderHttpClientInterface;
use MaxMessenger\Uploader\DTO\ResumableUploadInfo;
use MaxMessenger\Uploader\DTO\ResumableUploadStatus;
use MaxMessenger\Uploader\Exception\StreamLogicException;
use MaxMessenger\Uploader\Exception\UnknownResponseErrorException;
use MaxMessenger\Uploader\HttpClient\Body\StreamBody;
use Throwable;

use function is_array;
use function json_decode;
use function max;
use function preg_match;
use function rawurlencode;
use function sprintf;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

/**
 * Выполняет запросы для загрузки медиафайлов и получения информации о возобновляемой загрузке.
 */
final readonly class MaxUploaderHttpClient implements MaxUploaderHttpClientInterface
{
    /**
     * Создаёт клиент для запросов информации о загрузке и передачи медиафайлов.
     *
     * @param HttpClientInterface $httpClient HTTP-клиент для запросов к серверу загрузки.
     */
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {}

    public function getResumableUploadInfo(string $url): ResumableUploadInfo|ResumableUploadStatus
    {
        $response = $this->httpClient->request(new HttpRequest('GET', $url));

        return match ($response->getHttpCode()) {
            200 => self::parseResumableUploadInfo($response->getBody())
                ?? ResumableUploadStatus::UploadEndpointUnavailable,
            204 => ResumableUploadStatus::ContinueFromLastOffset,
            404 => ResumableUploadStatus::StartFromBeginning,
            default => ResumableUploadStatus::UploadEndpointUnavailable,
        };
    }

    public function postMultipartForm(
        string $url,
        StreamInterface $stream,
        string $contentType,
        int $timeout,
        ?int $lowSpeedLimit,
        ?int $lowSpeedTime,
        bool $expectJsonResponse,
    ): ?array {
        $request = new HttpRequest(
            'POST',
            $url,
            $expectJsonResponse ? ['Accept' => 'application/json; charset=utf-8'] : [],
            null,
            new StreamBody($stream, $contentType),
            max($timeout, 0),
            $lowSpeedLimit,
            $lowSpeedTime,
        );

        $response = $this->uploaderRequest($request, 200);

        if ($expectJsonResponse) {
            return self::parseJsonResponse($request, $response);
        }

        if ($response->getBody() !== '<retval>1</retval>') {
            throw new UnknownResponseErrorException($request, $response, self::parseError($response));
        }

        return null;
    }

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
    ): array|ResumableUploadInfo {
        /** @psalm-suppress DocblockTypeContradiction */
        if ($size < 1) {
            throw new StreamLogicException('The content size cannot be less than 1.');
        }
        $length = $stream->getSize();
        /** @psalm-suppress DocblockTypeContradiction */
        if ($length < 1) {
            throw new StreamLogicException('The fragment length cannot be less than 1.');
        }

        $headers = [
            'Content-Disposition' => sprintf('attachment; filename=%s', rawurlencode($postName)),
            'Content-Length' => (string) $length,
            'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $size),
        ];
        if ($expectJsonResponse) {
            $headers['Accept'] = 'application/json; charset=utf-8';
        }

        $request = new HttpRequest(
            'POST',
            $url,
            $headers,
            null,
            new StreamBody($stream, $contentType),
            max($timeout, 0),
            $lowSpeedLimit,
            $lowSpeedTime,
        );

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $response = $this->uploaderRequest($request, $offset + $length >= $size ? 200 : 201);

        if ($expectJsonResponse) {
            return self::parseJsonResponse($request, $response);
        }

        return self::parseResumableUploadInfo($response->getBody())
            ?? throw new UnknownResponseErrorException($request, $response, self::parseError($response));
    }

    private static function decodeJsonBody(HttpRequestInterface $request, HttpResponseInterface $response): array
    {
        if (!str_starts_with($response->getContentType() ?? '', 'application/json')) {
            throw new UnexpectedContentTypeException($request, $response);
        }

        try {
            $data = json_decode($response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonDecodeException($request, $response, $e);
        }

        if (!is_array($data) || $data === []) {
            throw new UnknownResponseErrorException($request, $response);
        }

        return $data;
    }

    private static function normalizeErrorData(array $data): array
    {
        if (isset($data['error_code'], $data['error_data'])) {
            /** @psalm-suppress MixedAssignment */
            $data['code'] ??= $data['error_code'];
            /** @psalm-suppress MixedAssignment */
            $data['message'] ??= $data['error_data'];
        }

        return $data;
    }

    private static function parseError(HttpResponseInterface|array $data): ?Error
    {
        try {
            if ($data instanceof HttpResponseInterface) {
                /** @psalm-suppress MixedAssignment */
                $data = json_decode($data->getBody(), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    return null;
                }
            }

            $error = Error::newFromData(self::normalizeErrorData($data));

            return $error->isValid() ? $error : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function parseJsonResponse(
        HttpRequestInterface $request,
        HttpResponseInterface $response,
    ): array {
        if ($response->getHttpCode() === 201) {
            return [];
        }

        $data = self::decodeJsonBody($request, $response);
        if (isset($data['error_code'], $data['error_data']) || isset($data['code'], $data['message'])) {
            throw new UnknownResponseErrorException($request, $response, self::parseError($data));
        }

        return $data;
    }

    private static function parseResumableUploadInfo(string $body): ?ResumableUploadInfo
    {
        if (!preg_match('/^0-(\d+)\/(\d+)$/', $body, $matches)) {
            return null;
        }

        /** @psalm-suppress ArgumentTypeCoercion */
        return new ResumableUploadInfo((int) $matches[1], (int) $matches[2]);
    }

    /**
     * @param positive-int $expectedHttpCode Ожидаемый код ответа.
     */
    private function uploaderRequest(
        HttpRequestInterface $request,
        int $expectedHttpCode,
    ): HttpResponseInterface {
        $response = $this->httpClient->request($request);

        if ($response->getHttpCode() !== $expectedHttpCode) {
            HttpException::throw($request, $response, self::parseError($response));
        }

        return $response;
    }
}
