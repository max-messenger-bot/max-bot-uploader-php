# Миграция с 0.2.1 на 1.0

Руководство сравнивает версию `0.2.1` с `1.0`. Если вы переходите с более ранней версии `0.x`,
проверьте также изменения между вашей версией и `0.2.1`.

## Обновление зависимостей

В `0.2.1` Composer-пакет назывался `max-messenger-bot/max-uploader-php`. Замените старую зависимость новой:

```bash
composer remove max-messenger-bot/max-uploader-php --no-update
composer require max-messenger-bot/max-bot-api-php:^1.0 max-messenger-bot/max-bot-uploader-php:"*" --with-all-dependencies
```

Если у вас уже используется имя `max-messenger-bot/max-bot-uploader-php`, выполните только вторую команду.

Обновляйте SDK и загрузчик вместе. Для загрузчика используется ограничение `*`, чтобы его совместимая версия
выбиралась по зависимости от SDK.

Пакет требует `max-messenger-bot/max-bot-api-php` `^1.0` и расширение `ext-mbstring`. Изменения SDK описаны в его
[руководстве по миграции](https://github.com/max-messenger-bot/max-bot-api-php/blob/main/docs/MigrationTo1.0.md).

Пакет `mj4444/simple-http-client` больше не является зависимостью. Если ваше приложение использует его напрямую,
объявите эту зависимость в своём `composer.json`.

## Переименованные классы

| 0.2.1                                                       | 1.0                                               |
|-------------------------------------------------------------|---------------------------------------------------|
| `MaxMessenger\Uploader\MaxSimpleUploader`                   | `MaxMessenger\Uploader\MaxMultipartFormUploader`  |
| `MaxMessenger\Uploader\MaxSimpleUploader\File`              | `MaxMessenger\Uploader\Content\FileContent`       |
| `MaxMessenger\Uploader\MaxSimpleUploader\StringFile`        | `MaxMessenger\Uploader\Content\StringContent`     |
| `MaxMessenger\Uploader\MaxUploader\Content\File`            | `MaxMessenger\Uploader\Content\FileContent`       |
| `MaxMessenger\Uploader\MaxUploader\Content\Stream`          | `MaxMessenger\Uploader\Content\StreamContent`     |
| `MaxMessenger\Uploader\MaxUploader\Content\StringFile`      | `MaxMessenger\Uploader\Content\StringContent`     |
| `MaxMessenger\Uploader\MaxUploader\Content\BaseStream`      | `MaxMessenger\Uploader\Content\BaseStreamContent` |
| `MaxMessenger\Uploader\MaxUploader\DTO\Progress`            | `MaxMessenger\Uploader\DTO\Progress`              |
| `MaxMessenger\Uploader\MaxUploader\DTO\UploadEventType`     | `MaxMessenger\Uploader\DTO\UploadEventType`       |
| `MaxMessenger\Uploader\MaxUploader\DTO\ResumableUploadInfo` | `MaxMessenger\Uploader\DTO\ResumableUploadInfo`   |

Первый аргумент `StringContent` называется `content`, а не `data`, как у `MaxSimpleUploader\StringFile`:
обновите именованные аргументы и обращения к свойству `data`.

Методы `getFileName()`, `getData()` и `getMime()` классов `MaxSimpleUploader\File` и `MaxSimpleUploader\StringFile`
удалены. Вместо `getMime()` используйте `getContentType()`.

`StringContent`, `FileContent` и `StreamContent` выбрасывают `StreamException` при создании с нулевым размером
содержимого. У `FileContent` и `StreamContent` проверяется переданный `size` или размер, определённый по ресурсу.
В `0.2.1` провайдеры для `MaxUploader` отклоняли пустое содержимое только при загрузке.

В отличие от `MaxSimpleUploader\File`, `FileContent` открывает файл и определяет его размер при создании.
Обработку ошибок открытия файла перенесите к вызову конструктора, если раньше она охватывала только загрузку.

`BaseStreamContent` больше не объявлен как `readonly class`: наследники этого класса не должны быть `readonly`.
Метод `isSeekable()` стал публичным: переопределяющие его наследники должны объявлять его как `public`.

## Именованные аргументы методов загрузки

В методах бывшего `MaxSimpleUploader` аргумент `$file` переименован в `$content`. При использовании именованных
аргументов в `uploadAudio()`, `uploadFile()`, `uploadFileEx()`, `uploadImage()`, `uploadImageEx()` и `uploadVideo()`
замените `file:` на `content:`.

## Продолжение загрузки по известному адресу

`MaxUploader::getLastMeta()` переименован в `getLastUploadEndpoint()`.

Аргумент `?UploadEndpoint $meta` удалён из методов `uploadAudio()`, `uploadFile()`, `uploadFileEx()`,
`uploadImage()`, `uploadImageEx()` и `uploadVideo()`. Адрес задаётся перед загрузкой через `setUploadEndpoint()`
и действует только для одной загрузки.

В `0.2.1` переданный адрес всегда означал попытку продолжить загрузку. В `1.0` по умолчанию загрузка
по заданному адресу начинается с начала; чтобы продолжить её, передайте `false` вторым аргументом:

```php
// 0.2.1
$token = $uploader->uploadVideo($path, $uploader->getLastMeta());

// 1.0
$uploader->setUploadEndpoint($uploader->getLastUploadEndpoint(), startFromBeginning: false);
$token = $uploader->uploadVideo($path);
```

## Обработчики событий

Если обработчик событий использует исчерпывающий `match` по `UploadEventType`, добавьте ветки для `Send` и `Error`.

## Таймауты и скорость передачи

Значение `lowSpeedLimit` по умолчанию изменено с `102400` на `1` байт в секунду.
Чтобы сохранить прежний предел, явно вызовите `setLowSpeedLimit(102400)`.

Таймаут сообщается исключением SDK `MaxMessenger\Bot\HttpClient\Exception\TimeoutException` вместо
`Mj4444\SimpleHttpClient\Exceptions\GeneralException` с кодом `OPERATION_TIMEOUTED`.
Ошибки HTTP — исключениями SDK из пространства имён `MaxMessenger\Bot\HttpClient\Exception\HttpResponse`.

## HTTP-клиент загрузки

Если вы настраивали HTTP-клиент для загрузки через основной клиент SDK, перенесите эту настройку
в `MaxApiConfig::setHttpClientForUploader()`.
Клиент загрузки, созданный по умолчанию, проверяет серверы загрузки по системным сертификатам: настройки
`useRussianTrustedCaCertificates`, `setCaCertificatePath()` и `setCaCertificateDir()` на него не действуют.

Классы `HttpClient\MaxUploadHttpClient`, `BaseRequest`, `GeneralResponse`, `SimpleUploadRequest`,
`ResumableInfoRequest` и `ResumableUploadRequest` удалены. Их заменяет `HttpClient\MaxUploaderHttpClient`
с интерфейсом `Contract\MaxUploaderHttpClientInterface`. Если вы обращались к удалённым классам напрямую,
обновите интеграцию под этот интерфейс.

## ContentInterface

Если вы реализовали `ContentInterface` самостоятельно, обновите реализацию:

- `getBody(int $offset, int $length, ?Closure $progressCallback): BodyInterface` заменён на
  `getStream(int $offset, int $length, ?Closure $progressCallback = null): StreamInterface`,
  где `StreamInterface` — `MaxMessenger\Bot\Contract\Http\StreamInterface`;
- добавлен метод `getContentType(): string`;
- добавлен метод `isSeekable(): bool`: возвращает, поддерживает ли источник перемотку;
- `getSize()` должен возвращать положительный размер (`positive-int` вместо `non-negative-int`).
  Пустое содержимое не поддерживается.

Обработчик `$progressCallback`, переданный в `getStream()`, теперь принимает два аргумента вместо одного:
число прочитанных байт от начала текущего потока и полную длину этого потока.

Подробнее — в описании [ContentInterface](ContentInterface.md).

## Исключения

- `SimpleUploaderException` удалено. Если не удаётся получить имя медиафайла, выбрасывается `StreamException`.
- При продолжении загрузки по заданному адресу обрабатывайте `UploadEndpointException`, если адрес недоступен
  или относится к медиафайлу другого размера.
- Для неверного диапазона, подтверждённого сервером, замените обработку `UnknownResponseErrorException`
  на `UnexpectedUploadRangeException`.
- `UnknownResponseErrorException` принимает запрос, ответ и необязательный `Error`; они доступны через
  `getRequest()`, `getResponse()` и `getError()`. Свойство `data` и метод `getData()` удалены:
  тело ответа получайте через `getResponse()->getBody()`.
- Ошибки тела ответа в HTTP-клиенте выбрасываются как `UnknownResponseErrorException`, включая ошибки API
  при ожидаемом HTTP-коде. `UnknownResponseFormatException` используется для данных без HTTP-контекста.
