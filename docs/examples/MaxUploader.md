# Примеры Resumable-загрузки

## Простая загрузка различных типов медиафайлов

**Загрузка файла без указания имени:**

```php
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\MaxUploader;

$apiClient = new MaxApiClient($accessToken, useRussianTrustedCaCertificates: true);
$uploader = new MaxUploader($apiClient);

$fileToken = $uploader->uploadFile(__FILE__);
$message = NewMessageBody::new()
    ->addFileAttachment($fileToken);
```

**Загрузка файла с указанием имени:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\Content\FileContent;

$fileToken = $uploader->uploadFile(new FileContent(__FILE__, 'file.txt'));
$message = NewMessageBody::new()
    ->addFileAttachment($fileToken);
```

**Загрузка файла с именем и текстом сообщения:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\Content\FileContent;

$fileToken = $uploader->uploadFile(new FileContent(__FILE__, 'Документация.txt'));
$message = NewMessageBody::make('Документацию к проекту')
    ->addFileAttachment($fileToken);
```

**Загрузка файла из строки:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\Content\StringContent;

$fileToken = $uploader->uploadFile(new StringContent('Содержимое файла', 'Отчёт.txt'));
$message = NewMessageBody::make('Прикладываю отчёт')
    ->addFileAttachment($fileToken);
```

**Загрузка изображения из ресурса:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\Content\StreamContent;

$imageToken = $uploader->uploadImage(new StreamContent($resource, 'image.webp'));
$message = NewMessageBody::new()
    ->addImageAttachment($imageToken);
```

**Загрузка аудиофайла:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;

$audioToken = $uploader->uploadAudio(__DIR__ . '/files/audio.mp3');
$message = NewMessageBody::make('Запись лекции')
    ->addAudioAttachment($audioToken);
```

**Загрузка нескольких изображений:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;

$image1Token = $uploader->uploadImage(__DIR__ . '/files/image.jpeg');
$image2Token = $uploader->uploadImage(__DIR__ . '/files/image.png');
$message = NewMessageBody::make('Фотографии с пикника')
    ->addImageAttachment($image1Token)
    ->addImageAttachment($image2Token);
```

**Загрузка нескольких видеофайлов:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;

$video1Token = $uploader->uploadVideo(__DIR__ . '/files/video1.mp4');
$video2Token = $uploader->uploadVideo(__DIR__ . '/files/video2.mp4');
$message = NewMessageBody::new()
    ->addVideoAttachment($video1Token)
    ->addVideoAttachment($video2Token);
```

**Загрузка изображения и видео в одном сообщении:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;

$imageToken = $uploader->uploadImage(__DIR__ . '/files/image.jpeg');
$videoToken = $uploader->uploadVideo(__DIR__ . '/files/video.mp4');
$message = NewMessageBody::new()
    ->addImageAttachment($imageToken)
    ->addVideoAttachment($videoToken);
```

## Получение детальной информации о загруженных медиафайлах

**Расширенная загрузка файла с получением деталей:**

```php
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\MaxUploader;

$apiClient = new MaxApiClient($accessToken, useRussianTrustedCaCertificates: true);
$uploader = new MaxUploader($apiClient);

$uploadedFile = $uploader->uploadFileEx(__FILE__);
echo 'FileId: ' . $uploadedFile->getFileId() . PHP_EOL;
echo 'Token: ' . $uploadedFile->getToken() . PHP_EOL;

$message = NewMessageBody::new()
    ->addFileAttachment($uploadedFile->getToken());
```

**Расширенная загрузка изображения с получением деталей:**

```php
$photoTokens = $uploader->uploadImageEx(__DIR__ . '/files/image.jpeg');
foreach ($photoTokens->getPhotos() as $photoId => $photoToken) {
    echo 'PhotoId: ' . $photoId . PHP_EOL;
    echo 'Token: ' . $photoToken->getToken() . PHP_EOL;
}

$photos = $photoTokens->getPhotos();
$imageToken = reset($photos)->getToken();
```

## Контроль скорости загрузки, количества повторных попыток и обработка ошибок

> [!NOTE]
> По умолчанию `lowSpeedLimit = 1` байт/с и `lowSpeedTime = 10` секунд.
> `PsrHttpClient` игнорирует эти параметры.

> [!NOTE]
> Таймаут задаётся в **миллисекундах**. `PsrHttpClient` его не применяет — там таймаут задают
> в настройках самого клиента.

**Настройка лимита низкой скорости (100 КиБ/с) и количества повторных попыток через методы:**

```php
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Uploader\MaxUploader;

$apiClient = new MaxApiClient($accessToken, useRussianTrustedCaCertificates: true);
$uploader = new MaxUploader($apiClient);

$uploader->setLowSpeedLimit(100 * 1024);
$uploader->setLowSpeedTime(10);
$uploader->setTimeout(10 * 60 * 1000);
$uploader->setRetryAttempts(10);
```

**Настройка лимита низкой скорости (100 КиБ/с) и количества повторных попыток через свойства:**

```php
$uploader->lowSpeedLimit = 100 * 1024;
$uploader->lowSpeedTime = 10;
$uploader->timeout = 10 * 60 * 1000;
$uploader->retryAttempts = 10;
```

**Возобновление загрузки при повторной попытке:**

При включённом `$uploader->smartResume` загрузчик запрашивает сведения об уже переданной части
медиафайла и продолжает с подтверждённого сервером смещения. Если установить `false`,
при повторной попытке загрузчик использует сохранённое смещение последнего фрагмента.

**Обработка таймаута при загрузке большого медиафайла:**

```php
use MaxMessenger\Bot\HttpClient\Exception\TimeoutException;

$uploader->timeout = 1;
$uploader->retryAttempts = 0;

try {
    $videoToken = $uploader->uploadVideo(__DIR__ . '/files/big-video.mp4');
} catch (TimeoutException $e) {
    echo 'Timeout: ' . $e->getMessage() . PHP_EOL;

    // Продолжаем загрузку с последнего полученного адреса, если он есть.
    $uploader->setTimeout(10 * 60 * 1000);
    $uploader->setUploadEndpoint($uploader->getLastUploadEndpoint(), startFromBeginning: false);
    $videoToken = $uploader->uploadVideo(__DIR__ . '/files/big-video.mp4');
}
```

## Обработка событий

**Обработка события Event:**

```php
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Uploader\DTO\UploadEventType;
use MaxMessenger\Uploader\MaxUploader;

$apiClient = new MaxApiClient($accessToken, useRussianTrustedCaCertificates: true);
$uploader = new MaxUploader($apiClient);

$uploader->setEventCallback(function (UploadEventType $event) {
    // FileInfoRequest — Генерируется перед запросом информации о состоянии загружаемого медиафайла.
    // Reset — Генерируется при перезапуске загрузки с начала.
    // Resume — Генерируется при возобновлении загрузки.
    // SendFragment — Генерируется перед началом загрузки очередного фрагмента.
    // Timeout — Генерируется при получении исключения с таймаутом.
    // Error — Генерируется при неожиданном HTTP-коде ответа на передачу медиафайла.
    // UrlRequest — Генерируется перед запросом ссылки для загрузки.
    echo 'Event: ' . $event->name . PHP_EOL;
});
```

**Обработка события Progress:**

```php
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Uploader\MaxUploader;
use MaxMessenger\Uploader\DTO\Progress;

$apiClient = new MaxApiClient($accessToken, useRussianTrustedCaCertificates: true);
$uploader = new MaxUploader($apiClient);

$uploader->setProgressCallback(function (Progress $progress) {
    $allBytesSent = $progress->fragmentOffset + $progress->bytesSent;
    echo "FragmentOffset: $progress->fragmentOffset, "; // Начало текущего загружаемого фрагмента
    echo "FragmentLength: $progress->fragmentLength, "; // Длина текущего загружаемого фрагмента
    echo "FragmentBytesSent: $progress->bytesSent, "; // Отправлено байт текущего фрагмента
    echo "AllBytesSent: $allBytesSent, "; // Отправлено байт всего от начала медиафайла
    echo "Size: $progress->size\n"; // Общая длина медиафайла
});
```

> Для получения событий Progress, загрузчик (провайдер) контента должен поддерживать это событие.
> Все загрузчики (провайдеры), реализованные в данном пакете, поддерживают это событие.
