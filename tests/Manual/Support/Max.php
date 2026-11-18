<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Manual\Support;

use LogicException;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\MaxApiConfig;
use MaxMessenger\Bot\MaxBot\Event\BaseEvent;
use MaxMessenger\Bot\MaxBot\Event\MessageCreatedEvent;
use MaxMessenger\Bot\Model\Enum\ChatType;
use MaxMessenger\Bot\Model\Enum\UpdateType;
use MaxMessenger\Bot\Model\Request\AudioAttachmentRequest;
use MaxMessenger\Bot\Model\Request\FileAttachmentRequest;
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Bot\Model\Request\PhotoAttachmentRequest;
use MaxMessenger\Bot\Model\Request\VideoAttachmentRequest;
use MaxMessenger\Bot\Model\Response\AudioAttachment;
use MaxMessenger\Bot\Model\Response\BotInfo;
use MaxMessenger\Bot\Model\Response\FileAttachment;
use MaxMessenger\Bot\Model\Response\PhotoAttachment;
use MaxMessenger\Bot\Model\Response\SendMessageResult;
use MaxMessenger\Bot\Model\Response\Update;
use MaxMessenger\Bot\Model\Response\UploadEndpoint;
use MaxMessenger\Bot\Model\Response\User;
use MaxMessenger\Bot\Model\Response\VideoAttachment;
use RuntimeException;
use Throwable;

use function array_keys;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function min;
use function sprintf;
use function strlen;
use function time;
use function trim;

/**
 * Вспомогательные действия с MAX для ручных сценариев.
 */
final class Max
{
    private static ?MaxApiClient $apiClient = null;
    private static ?int $marker = null;
    private static ?BotInfo $myInfo = null;
    private static ?int $testDialogId = null;
    private static ?User $testUser = null;
    private static ?int $testUserId = null;

    /**
     * Создаёт один API-клиент для ручных сценариев.
     *
     * @return MaxApiClient API-клиент текущего запуска.
     */
    public static function apiClient(): MaxApiClient
    {
        if (self::$apiClient === null) {
            self::initializeApiClient();
        }

        return self::$apiClient ?? throw new RuntimeException('Не удалось создать API-клиент.');
    }

    /**
     * Строго проверяет RAW-данные последнего адреса загрузки по значениям модели.
     *
     * @param UploadEndpoint|null $endpoint Последний адрес загрузки или null, если он не сохранён.
     * @param non-empty-string $check Название проверки для отчёта.
     */
    public static function checkUploadEndpoint(?UploadEndpoint $endpoint, string $check): void
    {
        if ($endpoint === null) {
            ManualTestReport::fail($check, 'Не сохранён адрес загрузки.');

            return;
        }

        $expected = ['url' => $endpoint->getUrl()];
        $token = $endpoint->getToken();
        if ($token !== null) {
            $expected['token'] = $token;
        }
        if (!Console::compareArrays($expected, $endpoint->getRawData())) {
            ManualTestReport::fail($check, 'RAW-данные адреса загрузки не совпали с ожидаемыми.');
        }
    }

    /**
     * @return BotInfo Информация о боте, загружаемая при первом обращении.
     */
    public static function getMyInfo(): BotInfo
    {
        return self::$myInfo ??= self::apiClient()->getMyInfo();
    }

    /**
     * @return int ID личного диалога с пользователем теста.
     */
    public static function getTestDialogId(): int
    {
        return self::$testDialogId ?? throw new LogicException('Не определён ID личного диалога.');
    }

    /**
     * @return int ID пользователя теста.
     */
    public static function getTestUserId(): int
    {
        return self::$testUserId ?? throw new LogicException('Не определён пользователь теста.');
    }

    /**
     * Определяет пользователя теста по первому сообщению и сохраняет ID личного диалога.
     *
     * @param MessageCreatedEvent $event Первое сообщение пользователя.
     */
    public static function identifyTestUser(MessageCreatedEvent $event): void
    {
        $message = $event->getMessage();
        $user = $message->getSender();
        if ($user === null) {
            throw new LogicException('Не удалось определить отправителя сообщения.');
        }

        self::$testUserId = $user->getUserId();
        self::$testDialogId = $message->getRecipient()->getChatId();
        self::$testUser = $user;

        Console::text('Получено сообщение от %s (ID %d).', $user->getFullName(), self::$testUserId);
    }

    /**
     * Отправляет новое сообщение текущему пользователю теста.
     *
     * @param NewMessageBody $message Тело отправляемого сообщения.
     * @param bool $disableLinkPreview Запретить автоматическое добавление предпросмотра ссылки.
     */
    public static function sendMessageToTestUser(
        NewMessageBody $message,
        bool $disableLinkPreview = false,
    ): void {
        $result = self::apiClient()->sendMessageToUser(self::getTestUserId(), $message, $disableLinkPreview);
        if (self::$testDialogId === null) {
            $recipient = $result->getMessage()->getRecipient();
            if ($recipient->getUserId() === self::getTestUserId()) {
                self::$testDialogId = $recipient->getChatId();
            }
        }

        self::checkSentMessage($message, $result, [
            'chat_type' => ChatType::Dialog->value,
            'chat_id' => self::getTestDialogId(),
            'user_id' => self::getTestUserId(),
        ], ['text' => $message->getText() ?? '']);
    }

    /**
     * Начинает тест личного диалога и определяет пользователя, если он ещё не определён.
     */
    public static function startDialogTest(): void
    {
        if (self::$testUser !== null) {
            return;
        }
        $bot = self::getMyInfo();
        $startText = '/teststart';
        Console::text('Бот: %s (ID %d)', $bot->getFullName(), $bot->getUserId());
        $username = $bot->getUsername();
        $botName = $username !== null ? '@' . $username : $bot->getFullName();
        Console::instruction('Отправьте боту %s в личный диалог точный текст: «%s»', $botName, $startText);

        self::identifyTestUser(self::waitForExactDialogText($startText, userId: null));
    }

    /**
     * Ожидает событие нужного типа, которое примет валидатор, и обновляет позицию опроса.
     *
     * @template T of BaseEvent
     * @param list<UpdateType>|null $expectedTypes Ожидаемые типы событий; null означает любой тип.
     * @param callable(T): bool $validator Возвращает true для нужного события, иначе событие пропускается.
     * @param int|true|null $userId ID пользователя; true означает пользователя теста, null отключает проверку.
     * @param positive-int $waitSeconds Общее время ожидания в секундах.
     * @return T Принятое событие.
     */
    public static function waitForEvent(
        ?array $expectedTypes,
        callable $validator,
        int|true|null $userId = true,
        int $waitSeconds = 600,
    ): BaseEvent {
        $client = self::apiClient();
        $userId = $userId === true ? self::getTestUserId() : $userId;
        $deadline = time() + $waitSeconds;

        while (($remainingSeconds = $deadline - time()) > 0) {
            $response = $client->getUpdates(
                limit: 1,
                timeout: min(30, $remainingSeconds),
                marker: self::$marker,
            );
            self::$marker = $response->getMarker() ?? self::$marker;

            foreach ($response->getUpdates() as $update) {
                if ($expectedTypes !== null && !in_array($update->getUpdateType(), $expectedTypes, true)) {
                    self::printSkippedUpdate($update, 'тип события не входит в список ожидаемых');

                    continue;
                }
                if (!$update->isValid()) {
                    self::printSkippedUpdate($update, 'в обновлении отсутствуют обязательные поля');

                    continue;
                }
                /** @var T $event */
                $event = BaseEvent::new($update, $client, []);
                if ($userId !== null && $event->getUserId() !== $userId) {
                    self::printSkippedUpdate($update, 'событие относится к другому пользователю');

                    continue;
                }
                if ($validator($event)) {
                    return $event;
                }
                self::printSkippedUpdate($update, 'событие отклонено валидатором');
            }
        }

        throw new RuntimeException(sprintf('За %d секунд ожидаемое событие не получено.', $waitSeconds));
    }

    /**
     * Ожидает точный текст от человека в личном диалоге с ботом.
     *
     * @param non-empty-string $expectedText Ожидаемый текст сообщения.
     * @param int|true|null $userId ID отправителя; true означает пользователя теста, null отключает проверку.
     * @return MessageCreatedEvent Найденное событие с ожидаемым текстом.
     */
    public static function waitForExactDialogText(
        string $expectedText,
        int|true|null $userId = true,
    ): MessageCreatedEvent {
        $validator = static function (MessageCreatedEvent $event) use ($expectedText): bool {
            $message = $event->getMessage();
            if ($message->getSender()?->isBot() !== false) {
                return false;
            }

            return $message->getText() === $expectedText;
        };

        return self::waitForMessageCreatedEvent($validator, userId: $userId);
    }

    /**
     * Ожидает событие создания сообщения, которое примет валидатор.
     *
     * @param callable(MessageCreatedEvent): bool $validator Проверяет событие с доступным сообщением.
     * @param ChatType|null $chatType Тип чата; null отключает проверку.
     * @param int|true|null $userId ID отправителя; true означает пользователя теста, null отключает проверку.
     * @param positive-int $waitSeconds Общее время ожидания в секундах.
     * @return MessageCreatedEvent Принятое событие с сообщением.
     */
    public static function waitForMessageCreatedEvent(
        callable $validator,
        ?ChatType $chatType = ChatType::Dialog,
        int|true|null $userId = true,
        int $waitSeconds = 600,
    ): MessageCreatedEvent {
        $matches = static function (MessageCreatedEvent $event) use ($validator, $chatType): bool {
            $message = $event->getMessage();

            return ($chatType === null || $message->getRecipient()->getChatType() === $chatType)
                && $validator($event);
        };

        return self::waitForEvent([UpdateType::MessageCreated], $matches, $userId, $waitSeconds);
    }

    /**
     * @param array<string, mixed> $expectedRecipient
     * @param array<string, mixed> $expectedBody
     */
    private static function checkSentMessage(
        NewMessageBody $message,
        SendMessageResult $result,
        array $expectedRecipient,
        array $expectedBody,
    ): void {
        $sentMessage = $result->getMessage();
        $bot = self::getMyInfo();
        $expectedSender = [
            'user_id' => $bot->getUserId(),
            'first_name' => $bot->getFirstName(),
            'is_bot' => true,
        ];
        if ($bot->getUsername() !== null) {
            $expectedSender['username'] = $bot->getUsername();
        }
        $lastActivityTime = $sentMessage->getSender()?->getLastActivityTimeRaw();
        if ($lastActivityTime !== null) {
            $expectedSender['last_activity_time'] = $lastActivityTime;
        }
        $expectedSender['name'] = $bot->getFullName();

        $body = $sentMessage->getBody();
        $requestAttachments = $message->getAttachments();
        if ($requestAttachments !== null) {
            $responseAttachments = $body->getAttachments() ?? [];
            $expectedAttachments = [];
            foreach ($requestAttachments as $index => $attachment) {
                /** @var array<string, mixed> $expectedAttachment */
                $expectedAttachment = json_decode(
                    json_encode($attachment, JSON_THROW_ON_ERROR),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                $responseAttachment = $responseAttachments[$index] ?? null;
                if (
                    ($attachment instanceof PhotoAttachmentRequest && $responseAttachment instanceof PhotoAttachment)
                    || ($attachment instanceof VideoAttachmentRequest && $responseAttachment instanceof VideoAttachment)
                    || ($attachment instanceof AudioAttachmentRequest && $responseAttachment instanceof AudioAttachment)
                    || ($attachment instanceof FileAttachmentRequest && $responseAttachment instanceof FileAttachment)
                ) {
                    $expectedAttachment = self::mediaAttachmentTemplate($responseAttachment);
                }
                $expectedAttachments[] = $expectedAttachment;
            }
            $expectedBody['attachments'] = $expectedAttachments;
        }
        $expectedBody = ['mid' => $body->getMid(), 'seq' => $body->getSeq(), ...$expectedBody];

        $expected = [
            'message' => [
                'recipient' => $expectedRecipient,
                'timestamp' => $sentMessage->getTimestampRaw(),
                'body' => $expectedBody,
            ],
        ];
        $expected['message']['sender'] = $expectedSender;
        $expected = self::sortedRawData($expected);
        $actual = self::sortedRawData($result->getRawData());
        if ($expected === $actual) {
            return;
        }

        Console::text(
            "Отправленное сообщение (формат: %s):\n%s",
            $message->getFormat()?->value ?? 'обычный текст',
            $message->getText() ?? '',
        );
        Console::diff($expected, $actual);

        ManualTestReport::fail(
            sprintf('Отправка сообщения (%s)', $message->getFormat()?->value ?? 'обычный текст'),
            'Ответ не совпал с ожидаемым. Различия выведены в консоль.',
            notifyUser: true,
        );
    }

    private static function initializeApiClient(): void
    {
        $console = Console::console();
        $console->section('Токен доступа');
        $token = Console::selectAccessTokenFromFile();
        if ($token !== null) {
            if (self::tryCreateApiClient($token)) {
                return;
            }
            $console->warning('Выбранный токен не прошёл проверку.');
        }

        while (true) {
            /** @var string|null $answer */
            $answer = $console->ask('Токен доступа Max Bot');
            $token = is_string($answer) ? trim($answer) : '';
            if ($token === '' || strlen($token) < 10) {
                $console->error('Токен доступа должен содержать не менее 10 символов.');

                continue;
            }
            if (self::tryCreateApiClient($token)) {
                return;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function mediaAttachmentTemplate(
        PhotoAttachment|VideoAttachment|AudioAttachment|FileAttachment $attachment,
    ): array {
        $payload = $attachment->getPayload();
        $expected = [
            'type' => $attachment->getTypeRaw(),
            'payload' => [
                'url' => $payload->getUrl(),
                'token' => $payload->getToken(),
            ],
        ];
        if ($attachment instanceof PhotoAttachment) {
            $expected['payload']['photo_id'] = $attachment->getPayload()->getPhotoId();
        } elseif ($attachment instanceof FileAttachment) {
            $expected['payload']['fileId'] = $attachment->getPayload()->getFileId();
            $expected['filename'] = $attachment->getFilename();
            $expected['size'] = $attachment->getSize();
        } else {
            $expected['payload']['id'] = $attachment->getPayload()->getId();
        }
        if ($attachment instanceof VideoAttachment) {
            $metadata = [
                'width' => $attachment->getWidth(),
                'height' => $attachment->getHeight(),
                'duration' => $attachment->getDuration(),
            ];
            foreach ($metadata as $key => $value) {
                if ($value !== null) {
                    $expected[$key] = $value;
                }
            }
            $thumbnail = $attachment->getThumbnail();
            if ($thumbnail !== null) {
                $expected['thumbnail'] = is_string($attachment->getRawData()['thumbnail'] ?? null)
                    ? $thumbnail->getUrl()
                    : ['url' => $thumbnail->getUrl()];
            }
        }

        return $expected;
    }

    private static function printSkippedUpdate(Update $update, string $reason): void
    {
        Console::text(
            'Пропущено событие %s: %s.',
            $update->getUpdateTypeRaw(),
            $reason,
        );
        Console::json($update->getRawData());
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function sortedRawData(array $data): array
    {
        foreach (array_keys($data) as $key) {
            if (is_array($data[$key])) {
                $data[$key] = self::sortedRawData($data[$key]);
            }
        }
        ksort($data);

        return $data;
    }

    /**
     * @param non-empty-string $accessToken
     */
    private static function tryCreateApiClient(string $accessToken): bool
    {
        $console = Console::console();

        try {
            $config = new MaxApiConfig($accessToken);
            // Проверка TLS намеренно отключена в ручных тестах для запуска в локальном окружении.
            $config->setVerifySslCertificate(false);
            $client = new MaxApiClient($config);
            if ($client->getSubscriptions()->getSubscriptions() !== []) {
                throw new RuntimeException('Для Long Polling у бота не должно быть Webhook-подписок.');
            }

            self::$apiClient = $client;
            $console->success('Токен доступа проверен.');

            return true;
        } catch (Throwable $exception) {
            $console->error('Токен не прошёл проверку: ' . $exception->getMessage());

            return false;
        }
    }
}
