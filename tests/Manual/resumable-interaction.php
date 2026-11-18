<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Manual;

use MaxMessenger\Bot\CurlHttpClient\CurlHttpClient;
use MaxMessenger\Bot\Model\Enum\UploadType;
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Bot\Model\Request\PhotoAttachmentRequest;
use MaxMessenger\Bot\Model\Request\PhotoAttachmentRequestPayload;
use MaxMessenger\Uploader\Content\FileContent;
use MaxMessenger\Uploader\Content\StringContent;
use MaxMessenger\Uploader\MaxUploader;
use MaxMessenger\Uploader\Tests\Manual\Support\Console;
use MaxMessenger\Uploader\Tests\Manual\Support\ManualEnvironment;
use MaxMessenger\Uploader\Tests\Manual\Support\ManualTestReport;
use MaxMessenger\Uploader\Tests\Manual\Support\Max;
use MaxMessenger\Uploader\Tests\Manual\Support\UploadMonitor;
use RuntimeException;
use Throwable;

use function basename;
use function count;
use function defined;
use function dirname;
use function file_get_contents;
use function in_array;
use function intdiv;
use function max;
use function sprintf;

/**
 * Список тестов:
 * - Определение пользователя по контрольному сообщению в личном диалоге.
 * - Загрузка изображения, файла, аудио и видео способом Resumable.
 * - Получение токена, адреса загрузки и числа попыток.
 * - События загрузки и завершающее уведомление о прогрессе.
 * - Загрузка из файла и предварительно прочитанной строки.
 * - Однократный таймаут 3000 мс на четвёртом фрагменте для каждого типа через FileContent.
 * - Отправка загруженных вложений в личный диалог.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$console = Console::console();

if (in_array('--help', $argv, true)) {
    $console->title('Ручной тест Resumable-загрузки');
    $console->text('Запуск: php tests/Manual/resumable-interaction.php');
    $console->note([
        'Нужен тестовый бот без Webhook-подписок и другого Long Polling.',
        'Используются файлы из tests/Manual/files.',
        'Для пяти частей используются image-large.jpeg, audio-large.mp3 и video.mp4.',
        'JPEG также загружается как обычный бинарный файл.',
        'Каждый тип проверяется через FileContent и StringContent.',
        'Файлы загружаются в MAX и отправляются в ваш личный диалог с ботом.',
        'Для каждого типа выполняется загрузка одним куском и пятью частями.',
        'Для каждого типа через FileContent проверяется однократный таймаут 3 с на четвёртом фрагменте.',
    ]);
    exit(0);
}

ManualEnvironment::initializeExceptionHandler();
ManualTestReport::reset();
$client = Max::apiClient();
Max::startDialogTest();
$uploader = new MaxUploader($client);
$uploader->setRetryAttempts(2)->setTimeout(120000);
foreach ([
    [UploadType::Image, 1],
    [UploadType::File, 1],
    [UploadType::Audio, 1],
    [UploadType::Video, 1],
    [UploadType::Image, 5],
    [UploadType::File, 5],
    [UploadType::Audio, 5],
    [UploadType::Video, 5],
] as [$type, $parts]) {
    foreach ([FileContent::class, StringContent::class] as $contentClass) {
        $check = 'Resumable / ' . $type->value . ' / ' . $contentClass . ' / частей: ' . $parts;
        $console->section($check);

        try {
            $file = match ($type) {
                UploadType::Image, UploadType::File => $parts === 5 ? 'image-large.jpeg' : 'image.jpeg',
                UploadType::Audio => $parts === 5 ? 'audio-large.mp3' : 'audio.mp3',
                UploadType::Video => 'video.mp4',
            };
            $path = __DIR__ . '/files/' . $file;
            if ($contentClass === FileContent::class) {
                $content = new FileContent($path);
            } else {
                $data = file_get_contents($path);
                if ($data === false || $data === '') {
                    throw new RuntimeException('Не удалось прочитать содержимое файла: ' . $path);
                }
                $content = new StringContent($data, $file);
            }
            $fragmentLength = max(1, intdiv($content->getSize() - 1, $parts) + 1);
            if ($parts > 1 && $content->getSize() <= 32768 * 2 * ($parts - 1)) {
                throw new RuntimeException('Файл слишком мал для загрузки пятью фрагментами.');
            }
            $uploader->setFragmentLength($fragmentLength);
            $monitor = new UploadMonitor();
            $uploader->setEventCallback($monitor->onEvent(...));
            $uploader->setProgressCallback($monitor->onProgress(...));

            $token = match ($type) {
                UploadType::Image => $uploader->uploadImage($content),
                UploadType::File => $uploader->uploadFile($content),
                UploadType::Audio => $uploader->uploadAudio($content),
                UploadType::Video => $uploader->uploadVideo($content),
            };
            Max::checkUploadEndpoint($uploader->getLastUploadEndpoint(), $check);
            /** @psalm-suppress TypeDoesNotContainType Проверяем контракт загрузчика во время выполнения. */
            if ($token === '') {
                throw new RuntimeException('Получен пустой токен загрузки.');
            }
            $monitor->check($uploader, $content, $check);

            $body = new NewMessageBody($check . ': ' . basename($path));
            match ($type) {
                UploadType::Image => $body->addImageAttachment($token),
                UploadType::File => $body->addFileAttachment($token),
                UploadType::Audio => $body->addAudioAttachment($token),
                UploadType::Video => $body->addVideoAttachment($token),
            };
            Max::sendMessageToTestUser($body);
            Console::success($check . ': вложение отправлено.');
        } catch (Throwable $exception) {
            ManualTestReport::fail($check, $exception);
        } finally {
            $uploader->setEventCallback(null)->setProgressCallback(null);
        }
    }
}

foreach (UploadType::cases() as $type) {
    $check = 'Resumable / ' . $type->value . ' / FileContent / таймаут 3 с на четвёртом фрагменте';
    $console->section($check);
    $httpClient = $client->getMaxHttpClient()->getHttpClientForUploader();
    $postDataStringThreshold = null;
    try {
        if (!$httpClient instanceof CurlHttpClient) {
            throw new RuntimeException('Для проверки таймаута при чтении потока нужен CurlHttpClient.');
        }
        $postDataStringThreshold = $httpClient->postDataStringThreshold;
        // Читаем даже маленькие фрагменты внутри curl, а не заранее в CURLOPT_POSTFIELDS.
        $httpClient->postDataStringThreshold = 0;
        $file = match ($type) {
            UploadType::Image, UploadType::File => 'image-large.jpeg',
            UploadType::Audio => 'audio-large.mp3',
            UploadType::Video => 'video.mp4',
        };
        $content = new FileContent(__DIR__ . '/files/' . $file);
        $uploader->setFragmentLength(max(1, intdiv($content->getSize() - 1, 5) + 1))->setTimeout(3000);
        $monitor = new UploadMonitor();
        // Чтение выполняется внутри HTTP-запроса: задержка превышает таймаут транспорта в 3000 мс.
        $monitor->delayFragmentOnce(4, 4000);
        $uploader->setEventCallback($monitor->onEvent(...));
        $uploader->setProgressCallback($monitor->onProgress(...));
        $token = match ($type) {
            UploadType::Image => $uploader->uploadImage($content),
            UploadType::File => $uploader->uploadFile($content),
            UploadType::Audio => $uploader->uploadAudio($content),
            UploadType::Video => $uploader->uploadVideo($content),
        };
        $monitor->check($uploader, $content, $check);
        Max::checkUploadEndpoint($uploader->getLastUploadEndpoint(), $check);
        $body = new NewMessageBody($check);
        match ($type) {
            UploadType::Image => $body->addImageAttachment($token),
            UploadType::File => $body->addFileAttachment($token),
            UploadType::Audio => $body->addAudioAttachment($token),
            UploadType::Video => $body->addVideoAttachment($token),
        };
        Max::sendMessageToTestUser($body);
        Console::success($check . ': загрузка завершена, вложение отправлено.');
    } catch (Throwable $exception) {
        ManualTestReport::fail($check, $exception);
    } finally {
        $uploader->setEventCallback(null)->setProgressCallback(null)->setTimeout(120000);
        if ($httpClient instanceof CurlHttpClient && $postDataStringThreshold !== null) {
            $httpClient->postDataStringThreshold = $postDataStringThreshold;
        }
    }
}

$check = 'Resumable / uploadFileEx';
$console->section($check);
try {
    $content = new FileContent(__DIR__ . '/files/image.jpeg');
    $uploader->setFragmentLength($content->getSize());
    $monitor = new UploadMonitor();
    $uploader->setEventCallback($monitor->onEvent(...));
    $uploader->setProgressCallback($monitor->onProgress(...));
    $uploadedFile = $uploader->uploadFileEx($content);
    $monitor->check($uploader, $content, $check);
    Max::checkUploadEndpoint($uploader->getLastUploadEndpoint(), $check);
    /** @psalm-suppress TypeDoesNotContainType Проверяем ответ во время выполнения. */
    if (!$uploadedFile->isValid() || $uploadedFile->getToken() === '') {
        throw new RuntimeException('Некорректный результат uploadFileEx().');
    }
    $expected = [
        'fileId' => $uploadedFile->getFileId(),
        'token' => $uploadedFile->getToken(),
    ];
    if (!Console::compareArrays($expected, $uploadedFile->getRawData())) {
        ManualTestReport::fail($check, 'RAW-данные uploadFileEx() не совпали с ожидаемыми.');
    } else {
        Console::success($check . ': RAW-данные проверены.');
    }
} catch (Throwable $exception) {
    ManualTestReport::fail($check, $exception);
} finally {
    $uploader->setEventCallback(null)->setProgressCallback(null);
}

$check = 'Resumable / uploadImageEx / photos';
$console->section($check);
try {
    $content = new FileContent(__DIR__ . '/files/image.jpeg');
    $uploader->setFragmentLength($content->getSize());
    $monitor = new UploadMonitor();
    $uploader->setEventCallback($monitor->onEvent(...));
    $uploader->setProgressCallback($monitor->onProgress(...));
    $photoTokens = $uploader->uploadImageEx($content);
    $monitor->check($uploader, $content, $check);
    Max::checkUploadEndpoint($uploader->getLastUploadEndpoint(), $check);
    if (!$photoTokens->isValid()) {
        throw new RuntimeException('Некорректный результат uploadImageEx().');
    }
    $photos = $photoTokens->getPhotos();
    foreach ($photos as $photo) {
        /** @psalm-suppress TypeDoesNotContainType Проверяем ответ во время выполнения. */
        if ($photo->getToken() === '') {
            throw new RuntimeException('Получен пустой токен изображения.');
        }
    }
    $console->text(sprintf('Получено токенов изображений: %d.', count($photos)));
    $body = new NewMessageBody($check);
    $body->addAttachment(new PhotoAttachmentRequest(new PhotoAttachmentRequestPayload(photos: $photos)));
    Max::sendMessageToTestUser($body);
    Console::success($check . ': изображение отправлено через массив photos.');
} catch (Throwable $exception) {
    ManualTestReport::fail($check, $exception);
} finally {
    $uploader->setEventCallback(null)->setProgressCallback(null);
}

if (ManualTestReport::report() && !defined('MaxMessenger\Uploader\Tests\Manual\MAX_MANUAL_ALL_INTERACTION')) {
    exit(1);
}
