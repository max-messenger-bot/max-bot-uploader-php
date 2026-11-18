<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Manual;

use MaxMessenger\Bot\Model\Enum\UploadType;
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Bot\Model\Request\PhotoAttachmentRequest;
use MaxMessenger\Bot\Model\Request\PhotoAttachmentRequestPayload;
use MaxMessenger\Uploader\Content\FileContent;
use MaxMessenger\Uploader\Content\StringContent;
use MaxMessenger\Uploader\MaxMultipartFormUploader;
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
use function sprintf;

/**
 * Список тестов:
 * - Определение пользователя по контрольному сообщению в личном диалоге.
 * - Загрузка изображения, файла, аудио и видео способом Multipart.
 * - Получение токена, адреса загрузки и числа попыток.
 * - События загрузки и завершающее уведомление о прогрессе.
 * - Загрузка из файла и предварительно прочитанной строки.
 * - Отправка загруженных вложений в личный диалог.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$console = Console::console();

if (in_array('--help', $argv, true)) {
    $console->title('Ручной тест Multipart-загрузки');
    $console->text('Запуск: php tests/Manual/multipart-form-interaction.php');
    $console->note([
        'Нужен тестовый бот без Webhook-подписок и другого Long Polling.',
        'Используются файлы image.jpeg, audio.mp3 и video.mp4 из tests/Manual/files.',
        'image.jpeg также загружается как обычный бинарный файл.',
        'Каждый тип проверяется через FileContent и StringContent.',
        'Файлы загружаются в MAX и отправляются в ваш личный диалог с ботом.',
    ]);
    exit(0);
}

ManualEnvironment::initializeExceptionHandler();
ManualTestReport::reset();
$client = Max::apiClient();
Max::startDialogTest();
$uploader = new MaxMultipartFormUploader($client);
$uploader->setRetryAttempts(2)->setTimeout(120000);

foreach (UploadType::cases() as $type) {
    foreach ([FileContent::class, StringContent::class] as $contentClass) {
        $check = 'Multipart / ' . $type->value . ' / ' . $contentClass;
        $console->section($check);

        try {
            $file = match ($type) {
                UploadType::Image, UploadType::File => 'image.jpeg',
                UploadType::Audio => 'audio.mp3',
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

$check = 'Multipart / uploadFileEx';
$console->section($check);
try {
    $content = new FileContent(__DIR__ . '/files/image.jpeg');
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

$check = 'Multipart / uploadImageEx / photos';
$console->section($check);
try {
    $content = new FileContent(__DIR__ . '/files/image.jpeg');
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
