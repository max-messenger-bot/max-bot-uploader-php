# Примеры Multipart-загрузки

## Простая загрузка различных типов медиафайлов

**Загрузка файла без указания имени:**

```php
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\MaxMultipartFormUploader;

$apiClient = new MaxApiClient($accessToken, useRussianTrustedCaCertificates: true);
$formUploader = new MaxMultipartFormUploader($apiClient);

$fileToken = $formUploader->uploadFile(__FILE__);
$message = NewMessageBody::new()
    ->addFileAttachment($fileToken);
```

**Загрузка файла с указанием имени:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\Content\FileContent;

$fileToken = $formUploader->uploadFile(new FileContent(__FILE__, 'file.txt'));
$message = NewMessageBody::new()
    ->addFileAttachment($fileToken);
```

**Загрузка файла с именем и текстом сообщения:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\Content\FileContent;

$fileToken = $formUploader->uploadFile(new FileContent(__FILE__, 'Документация.txt'));
$message = NewMessageBody::make('Документацию к проекту')
    ->addFileAttachment($fileToken);
```

**Загрузка файла из строки:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\Content\StringContent;

$fileToken = $formUploader->uploadFile(new StringContent('Содержимое файла', 'Отчёт.txt'));
$message = NewMessageBody::make('Прикладываю отчёт')
    ->addFileAttachment($fileToken);
```

**Загрузка аудиофайла:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;

$audioToken = $formUploader->uploadAudio(__DIR__ . '/files/audio.mp3');
$message = NewMessageBody::make('Запись лекции')
    ->addAudioAttachment($audioToken);
```

**Загрузка нескольких изображений:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;

$image1Token = $formUploader->uploadImage(__DIR__ . '/files/image.jpeg');
$image2Token = $formUploader->uploadImage(__DIR__ . '/files/image.png');
$message = NewMessageBody::make('Фотографии с пикника')
    ->addImageAttachment($image1Token)
    ->addImageAttachment($image2Token);
```

**Загрузка нескольких видеофайлов:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;

$video1Token = $formUploader->uploadVideo(__DIR__ . '/files/video1.mp4');
$video2Token = $formUploader->uploadVideo(__DIR__ . '/files/video2.mp4');
$message = NewMessageBody::new()
    ->addVideoAttachment($video1Token)
    ->addVideoAttachment($video2Token);
```

**Загрузка изображения и видео в одном сообщении:**

```php
use MaxMessenger\Bot\Model\Request\NewMessageBody;

$imageToken = $formUploader->uploadImage(__DIR__ . '/files/image.jpeg');
$videoToken = $formUploader->uploadVideo(__DIR__ . '/files/video.mp4');
$message = NewMessageBody::new()
    ->addImageAttachment($imageToken)
    ->addVideoAttachment($videoToken);
```

## Получение детальной информации о загруженных медиафайлах

**Расширенная загрузка файла с получением деталей:**

```php
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\MaxMultipartFormUploader;

$apiClient = new MaxApiClient($accessToken, useRussianTrustedCaCertificates: true);
$formUploader = new MaxMultipartFormUploader($apiClient);

$uploadedFile = $formUploader->uploadFileEx(__FILE__);
echo 'FileId: ' . $uploadedFile->getFileId() . PHP_EOL;
echo 'Token: ' . $uploadedFile->getToken() . PHP_EOL;

$message = NewMessageBody::new()
    ->addFileAttachment($uploadedFile->getToken());
```

**Расширенная загрузка изображения с получением деталей:**

```php
$photoTokens = $formUploader->uploadImageEx(__DIR__ . '/files/image.jpeg');
foreach ($photoTokens->getPhotos() as $photoId => $photoToken) {
    echo 'PhotoId: ' . $photoId . PHP_EOL;
    echo 'Token: ' . $photoToken->getToken() . PHP_EOL;
}

$photos = $photoTokens->getPhotos();
$imageToken = reset($photos)->getToken();
```

## Отслеживание прогресса

**Обработка события Progress:**

```php
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Uploader\DTO\Progress;
use MaxMessenger\Uploader\MaxMultipartFormUploader;

$apiClient = new MaxApiClient($accessToken, useRussianTrustedCaCertificates: true);
$formUploader = new MaxMultipartFormUploader($apiClient);

$formUploader->setProgressCallback(function (Progress $progress) {
    echo "BytesSent: $progress->bytesSent, "; // Отправлено байт
    echo "Size: $progress->size\n"; // Общий размер формы
});

$videoToken = $formUploader->uploadVideo(__DIR__ . '/files/big-video.mp4');
```

> Форма отправляется одним куском, поэтому `fragmentOffset` всегда `0`, а `fragmentLength` равен `size`.
> В размер входит и обвязка формы: заголовки частей и замыкающая граница, а не только сам медиафайл.

## Контроль скорости загрузки и обработка ошибок

> [!NOTE]
> По умолчанию `lowSpeedLimit = 1` байт/с и `lowSpeedTime = 10` секунд.
> `PsrHttpClient` игнорирует эти параметры.

> [!NOTE]
> Таймаут задаётся в **миллисекундах**. `PsrHttpClient` его не применяет — там таймаут задают
> в настройках самого клиента.

**Настройка лимита низкой скорости (100 КиБ/с) и количества повторных попыток через методы:**

```php
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Uploader\MaxMultipartFormUploader;

$apiClient = new MaxApiClient($accessToken, useRussianTrustedCaCertificates: true);
$formUploader = new MaxMultipartFormUploader($apiClient);

$formUploader->setLowSpeedLimit(100 * 1024);
$formUploader->setLowSpeedTime(10);
$formUploader->setTimeout(10 * 60 * 1000);
$formUploader->setRetryAttempts(10);
```

**Настройка лимита низкой скорости (100 КиБ/с) через свойства:**

```php
$formUploader->lowSpeedLimit = 100 * 1024;
$formUploader->lowSpeedTime = 10;
$formUploader->timeout = 10 * 60 * 1000;
$formUploader->retryAttempts = 10;
```

**Обработка таймаута при загрузке большого медиафайла:**

```php
use MaxMessenger\Bot\HttpClient\Exception\TimeoutException;

$formUploader->timeout = 1;
$formUploader->retryAttempts = 0;

try {
    $videoToken = $formUploader->uploadVideo(__DIR__ . '/files/big-video.mp4');
} catch (TimeoutException $e) {
    echo 'Timeout: ' . $e->getMessage() . PHP_EOL;
}
```
