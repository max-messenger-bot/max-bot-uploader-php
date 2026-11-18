<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader;

use Closure;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\Http\OkException;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\Http\PartialUploadException;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\Http\RangeNotSatisfiableException;
use MaxMessenger\Bot\HttpClient\Exception\TimeoutException;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\Model\Enum\UploadType;
use MaxMessenger\Bot\Model\Response\PhotoTokens;
use MaxMessenger\Bot\Model\Response\UploadedFile;
use MaxMessenger\Bot\Model\Response\UploadEndpoint;
use MaxMessenger\Bot\PsrHttpClient\PsrHttpClient;
use MaxMessenger\Uploader\Content\FileContent;
use MaxMessenger\Uploader\Contract\ContentInterface;
use MaxMessenger\Uploader\Contract\MaxUploaderHttpClientInterface;
use MaxMessenger\Uploader\DTO\Progress;
use MaxMessenger\Uploader\DTO\UploadEventType;
use MaxMessenger\Uploader\Exception\StreamException;
use MaxMessenger\Uploader\Exception\UnknownResponseFormatException;
use MaxMessenger\Uploader\HttpClient\MaxUploaderHttpClient;

use function is_string;
use function mb_strlen;
use function preg_match;
use function reset;
use function strpbrk;

/**
 * Общие настройки и HTTP-клиент загрузчиков медиафайлов.
 */
abstract class BaseUploader
{
    /**
     * @var Closure(UploadEventType $eventType): void|null Обработчик событий загрузки или `null`.
     */
    public ?Closure $eventCallback = null;
    /**
     * Наименьшая допустимая скорость передачи в байтах в секунду.
     *
     * {@see PsrHttpClient} игнорирует эту настройку.
     *
     * @var positive-int|null `null` — скорость не проверяется.
     */
    public ?int $lowSpeedLimit = 1;
    /**
     * Сколько секунд скорость может держаться ниже предела, прежде чем передача будет прервана.
     *
     * {@see PsrHttpClient} игнорирует эту настройку.
     *
     * @var positive-int|null `null` — скорость не проверяется.
     */
    public ?int $lowSpeedTime = 10;
    /**
     * @var Closure(Progress $progress): void|null Обработчик прогресса загрузки или `null`.
     */
    public ?Closure $progressCallback = null;
    /**
     * @var non-negative-int Число повторных попыток после таймаута или неожиданного ответа сервера.
     */
    public int $retryAttempts = 100;
    /**
     * Максимальное время одного запроса на загрузку в миллисекундах.
     *
     * {@see PsrHttpClient} таймаут не применяет: там его задают
     * в настройках самого клиента.
     *
     * @var non-negative-int `0` — ждать неограниченно.
     */
    public int $timeout = 0;
    /**
     * Адрес загрузки. Если `null`, адрес будет получен автоматически.
     */
    public ?UploadEndpoint $uploadEndpoint = null;
    /**
     * @var non-negative-int Количество попыток загрузки последнего медиафайла.
     */
    protected int $lastUploadAttempts = 0;
    /**
     * Последний адрес, использованный при загрузке, или `null`, если адрес ещё не получен.
     */
    protected ?UploadEndpoint $lastUploadEndpoint = null;
    /**
     * HTTP-клиент для передачи медиафайлов.
     */
    protected readonly MaxUploaderHttpClientInterface $uploadClient;

    /**
     * Создаёт загрузчик с клиентом API и клиентом для передачи медиафайлов.
     *
     * @param MaxApiClient $apiClient Клиент API, доступный наследникам загрузчика.
     * @param MaxUploaderHttpClientInterface|null $uploadClient HTTP-клиент для загрузки медиафайлов.
     *     Если `null`, клиент создаётся автоматически.
     */
    public function __construct(
        private readonly MaxApiClient $apiClient,
        ?MaxUploaderHttpClientInterface $uploadClient = null,
    ) {
        $this->uploadClient = $uploadClient
            ?? new MaxUploaderHttpClient($this->apiClient->getMaxHttpClient()->getHttpClientForUploader());
    }

    /**
     * @return MaxApiClient Клиент API, переданный загрузчику.
     */
    public function getApiClient(): MaxApiClient
    {
        return $this->apiClient;
    }

    /**
     * @return non-negative-int Количество попыток загрузки последнего медиафайла.
     */
    public function getLastUploadAttempts(): int
    {
        return $this->lastUploadAttempts;
    }

    /**
     * @return UploadEndpoint|null Последний адрес загрузки или `null`, если он ещё не получен.
     */
    public function getLastUploadEndpoint(): ?UploadEndpoint
    {
        return $this->lastUploadEndpoint;
    }

    /**
     * @return MaxUploaderHttpClientInterface Клиент для передачи медиафайлов.
     */
    public function getMaxUploaderHttpClient(): MaxUploaderHttpClientInterface
    {
        return $this->uploadClient;
    }

    /**
     * @param Closure(UploadEventType $eventType): void|null $eventCallback Обработчик событий загрузки
     *     или `null` для отключения.
     * @return $this
     */
    public function setEventCallback(?Closure $eventCallback): static
    {
        $this->eventCallback = $eventCallback;

        return $this;
    }

    /**
     * @param positive-int|null $lowSpeedLimit Минимальная скорость передачи в байтах в секунду
     *     или `null` для отключения проверки.
     * @return $this
     */
    public function setLowSpeedLimit(?int $lowSpeedLimit): static
    {
        $this->lowSpeedLimit = $lowSpeedLimit;

        return $this;
    }

    /**
     * @param positive-int|null $lowSpeedTime Допустимая длительность низкой скорости в секундах
     *     или `null` для отключения проверки.
     * @return $this
     */
    public function setLowSpeedTime(?int $lowSpeedTime): static
    {
        $this->lowSpeedTime = $lowSpeedTime;

        return $this;
    }

    /**
     * @param Closure(Progress $progress): void|null $progressCallback Обработчик прогресса загрузки
     *     или `null` для отключения.
     * @return $this
     */
    public function setProgressCallback(?Closure $progressCallback): static
    {
        $this->progressCallback = $progressCallback;

        return $this;
    }

    /**
     * @param non-negative-int $retryAttempts Количество повторных попыток после таймаута
     *     или неожиданного ответа сервера.
     * @return $this
     */
    public function setRetryAttempts(int $retryAttempts): static
    {
        $this->retryAttempts = $retryAttempts;

        return $this;
    }

    /**
     * @param non-negative-int $timeout Максимальное время одного запроса на загрузку в миллисекундах;
     *     `0` — без ограничения.
     * @return $this
     */
    public function setTimeout(int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * @param UploadEndpoint|null $uploadEndpoint Адрес загрузки или `null` для автоматического получения адреса.
     * @return $this
     */
    public function setUploadEndpoint(?UploadEndpoint $uploadEndpoint): static
    {
        $this->uploadEndpoint = $uploadEndpoint;

        return $this;
    }

    /**
     * Загружает аудиофайл.
     *
     * @param ContentInterface|non-empty-string $content Данные или путь к аудиофайлу.
     * @return non-empty-string Токен загруженного аудиофайла.
     */
    public function uploadAudio(ContentInterface|string $content): string
    {
        return $this->uploadEx($content, UploadType::Audio, false);
    }

    /**
     * Загружает файл.
     *
     * @param ContentInterface|non-empty-string $content Данные или путь к файлу.
     * @return non-empty-string Токен загруженного файла.
     */
    public function uploadFile(ContentInterface|string $content): string
    {
        return $this->uploadFileEx($content)->getToken();
    }

    /**
     * Загружает файл и возвращает сведения о нём.
     *
     * @param ContentInterface|non-empty-string $content Данные или путь к файлу.
     * @return UploadedFile Сведения о загруженном файле.
     */
    public function uploadFileEx(ContentInterface|string $content): UploadedFile
    {
        $data = $this->uploadEx($content, UploadType::File, true);

        $model = UploadedFile::newFromData($data);
        if (!$model->isValid()) {
            throw new UnknownResponseFormatException($data);
        }

        return $model;
    }

    /**
     * Загружает изображение.
     *
     * @param ContentInterface|non-empty-string $content Данные или путь к файлу с изображением.
     * @return non-empty-string Токен загруженного изображения.
     */
    public function uploadImage(ContentInterface|string $content): string
    {
        $photos = $this->uploadImageEx($content)->getPhotos();
        $item = reset($photos);

        /** @psalm-suppress DocblockTypeContradiction */
        if ($item === false || empty($token = $item->getToken())) {
            throw new UnknownResponseFormatException($photos);
        }

        return $token;
    }

    /**
     * Загружает изображение и возвращает набор его токенов.
     *
     * @param ContentInterface|non-empty-string $content Данные или путь к файлу с изображением.
     * @return PhotoTokens Токены загруженного изображения.
     */
    public function uploadImageEx(ContentInterface|string $content): PhotoTokens
    {
        $data = $this->uploadEx($content, UploadType::Image, true);

        $model = PhotoTokens::newFromData($data);
        if (!$model->isValid()) {
            throw new UnknownResponseFormatException($data);
        }

        return $model;
    }

    /**
     * Загружает видеофайл.
     *
     * @param ContentInterface|non-empty-string $content Данные или путь к видеофайлу.
     * @return non-empty-string Токен загруженного видеофайла.
     */
    public function uploadVideo(ContentInterface|string $content): string
    {
        return $this->uploadEx($content, UploadType::Video, false);
    }

    protected function getUploadEndpoint(UploadType $type): UploadEndpoint
    {
        $this->eventCallback?->__invoke(UploadEventType::UrlRequest);

        return $this->apiClient->getUploadUrl($type);
    }

    /**
     * @return non-empty-string
     */
    protected static function getUploadToken(UploadEndpoint $uploadEndpoint): string
    {
        /** @psalm-suppress RiskyTruthyFalsyComparison */
        return $uploadEndpoint->getToken()
            ?: throw new UnknownResponseFormatException($uploadEndpoint->getRawData());
    }

    /**
     * @param non-negative-int $frOffset
     * @param non-negative-int|null $size
     * @return (Closure(non-negative-int $bytesSent, non-negative-int $totalBytes): void)|null
     */
    protected function makeProgressCallback(int $frOffset = 0, ?int $size = null): ?Closure
    {
        $callback = $this->progressCallback;
        if ($callback === null) {
            return null;
        }

        return
            /**
             * @param non-negative-int $bytesSent
             * @param non-negative-int $totalBytes
             */
            static function (int $bytesSent, int $totalBytes) use ($frOffset, $size, $callback): void {
                $callback(new Progress($frOffset, $totalBytes, $bytesSent, $size ?? $totalBytes));
            };
    }

    /**
     * @template T of bool
     * @param T $json
     * @return (T is true ? array : non-empty-string)
     */
    abstract protected function uploadAttempt(
        ContentInterface $content,
        UploadType $type,
        bool $endpointProvided,
        bool $json,
        bool &$canRetry,
    ): array|string;

    /**
     * @template T of bool
     * @param ContentInterface|non-empty-string $content
     * @param T $json
     * @return (T is true ? array : non-empty-string)
     */
    private function uploadEx(ContentInterface|string $content, UploadType $type, bool $json): array|string
    {
        $this->lastUploadAttempts = 0;
        $this->lastUploadEndpoint = $this->uploadEndpoint;
        $endpointProvided = $this->uploadEndpoint !== null;
        $this->uploadEndpoint = null;

        if (is_string($content)) {
            $content = new FileContent($content);
        }
        $this->validateContent($content);

        while (true) {
            ++$this->lastUploadAttempts;
            $canRetry = true;
            try {
                return $this->uploadAttempt($content, $type, $endpointProvided, $json, $canRetry);
            } catch (TimeoutException|OkException|PartialUploadException|RangeNotSatisfiableException $e) {
                $this->eventCallback?->__invoke(
                    $e instanceof TimeoutException ? UploadEventType::Timeout : UploadEventType::Error,
                );

                if (!$canRetry || $this->lastUploadAttempts > $this->retryAttempts) {
                    throw $e;
                }
            }
        }
    }

    private function validateContent(ContentInterface $content): void
    {
        if ($content->getSize() < 1) {
            throw new StreamException('Cannot upload empty file.');
        }

        $postName = $content->getPostName();
        /** @psalm-suppress TypeDoesNotContainType */
        if ($postName === '') {
            throw new StreamException('Failed to extract post name.');
        }
        if (strpbrk($postName, '\\/:*"<>|') !== false || preg_match('/[\x00-\x1F]/', $postName) === 1) {
            throw new StreamException('File name contains forbidden characters.');
        }
        if (mb_strlen($postName, 'UTF-8') > 200) {
            throw new StreamException('File name must not exceed 200 characters.');
        }
    }
}
