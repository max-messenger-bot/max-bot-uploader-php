# MAX Media Uploader for PHP

[![Latest Version](https://img.shields.io/packagist/v/max-messenger-bot/max-bot-uploader-php)](https://packagist.org/packages/max-messenger-bot/max-bot-uploader-php)
[![PHP Version](https://img.shields.io/packagist/dependency-v/max-messenger-bot/max-bot-uploader-php/php)](https://packagist.org/packages/max-messenger-bot/max-bot-uploader-php)
[![CI](https://github.com/max-messenger-bot/max-bot-uploader-php/actions/workflows/ci.yml/badge.svg)](https://github.com/max-messenger-bot/max-bot-uploader-php/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/max-messenger-bot/max-bot-uploader-php)](LICENSE)

<img src="docs/images/bot-icon.webp" align="left">

Этот пакет предназначен для загрузки медиафайлов на серверы API MAX в полностью объектно-ориентированном формате.
<br>**Multipart upload** и **Resumable upload** методы.<br>Полный контроль над загрузкой.

**Простой пример отправки сообщения с вложением:**

```php
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use MaxMessenger\Uploader\MaxUploader;

$apiClient = new MaxApiClient('your-access-token', useRussianTrustedCaCertificates: true);
$uploader = new MaxUploader($apiClient);

$fileToken = $uploader->uploadFile(__FILE__);
$message = NewMessageBody::new()
    ->addFileAttachment($fileToken);
$apiClient->sendMessageToUser(12345678, $message);
```

> [!WARNING]
> По поводу ошибок в клиенте, пожалуйста обращайтесь ко мне напрямую:
>   - МАКС: [Евгений](https://max.ru/u/f9LHodD0cOID7ezkLpMv_5wNX9YmRCmk-0bp4q1uWCRtrdClF9F21Buxhyk)
>   - Telegram: [mj4444ru](https://t.me/mj4444ru)

> [!IMPORTANT]
> В конструкторах используйте именованные параметры: состав и порядок параметров повторяют схему API
> и могут измениться при её обновлении. Именованные параметры обеспечивают обратную совместимость.

> [!NOTE]
> Возобновление загрузки с места прерывания доступно не всегда. Сервера МАКС сами определяют, можно ли
> возобновить загрузку или её нужно начать с начала. Загрузчик выполняет требования сервера, поэтому загрузка
> с начала не является ошибкой загрузчика.

## Основные особенности

- Реализована загрузка медиафайлов на сервера обоими поддерживаемыми способами.
- Возможность вести логи, следить за происходящими процессами, следить за прогрессом загрузки.
- Это полностью объектно-ориентированный код без array shapes (object-like arrays).
- Для загрузки медиафайлов не требуется изучение официального API.
- В большинстве случаев для понимания работы, Вам достаточно будет посмотреть [примеры кода](docs/examples/README.md).
- Весь функционал разбит на слои, каждый слой может быть частично или полностью заменён Вашей реализацией.

## Способы загрузки

- **Multipart upload** — Более простой, но менее надёжный способ загрузки
  (реализован классом `MaxMultipartFormUploader`).
- **Resumable upload** — Рекомендуемый способ загрузки (реализован классом `MaxUploader`).

Оба загрузчика принимают необязательный второй аргумент `MaxUploaderHttpClientInterface`.
Можно передать собственную реализацию интерфейса или экземпляр `MaxUploaderHttpClient`.
Без второго аргумента клиент загрузки создаётся из HTTP-клиента основного клиента API.
Клиент API и текущий клиент загрузки доступны через `getApiClient()` и `getMaxUploaderHttpClient()`.

## Документация в коде

I believe that documentation in public code should be in English.
However, the documentation in this project is written in Russian because the messenger is aimed at a
Russian-speaking audience, and Russian is the primary language of developers who create bots for it.

Я считаю, что документация в публичном коде должна быть на английском языке.
Однако документация в этом проекте написана на русском языке, поскольку мессенджер ориентирован на русскоязычную
аудиторию, а русский — основной язык разработчиков, создающих для него ботов.

## Установка

```bash
composer require max-messenger-bot/max-bot-uploader-php:"*"
```

> [!NOTE]
> Ограничение `"*"` позволяет Composer подобрать версию загрузчика, совместимую с установленной версией
> основного пакета `max-messenger-bot/max-bot-api-php`.

### Требования

- PHP 8.2+
- ext-mbstring

### Зависимости

- `max-messenger-bot/max-bot-api-php` ^1.0

## Примеры

Больше примеров смотрите в документации в разделе [примеры](docs/examples/README.md).

## Документация

- [Интерфейс ContentInterface](docs/ContentInterface.md)
- [Примеры Multipart-загрузки](docs/examples/MaxMultipartFormUploader.md)
- [Примеры Resumable-загрузки](docs/examples/MaxUploader.md)
- [Миграция с 0.2.1 на 1.0](docs/MigrationTo1.0.md)
