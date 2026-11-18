<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Manual;

const MAX_MANUAL_ALL_INTERACTION = true;

use MaxMessenger\Uploader\Tests\Manual\Support\Console;
use MaxMessenger\Uploader\Tests\Manual\Support\ManualEnvironment;
use MaxMessenger\Uploader\Tests\Manual\Support\ManualTestReport;
use MaxMessenger\Uploader\Tests\Manual\Support\Max;

use function dirname;
use function in_array;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$console = Console::console();

if (in_array('--help', $argv, true)) {
    $console->title('Полный интерактивный тест загрузчиков');
    $console->text('Запуск: php tests/Manual/all-interaction.php');
    $console->note([
        'Последовательно выполняются Multipart- и Resumable-сценарии.',
        'Нужны тестовый бот и личный диалог с ним. Файлы берутся из tests/Manual/files.',
    ]);
    exit(0);
}

ManualEnvironment::initializeExceptionHandler();
Max::apiClient();

$blocks = [
    'multipart-form-interaction.php' => 'Multipart: загрузка одним запросом',
    'resumable-interaction.php' => 'Resumable: загрузка по фрагментам',
];
foreach ($blocks as $file => $title) {
    $console->section($title);
    ManualTestReport::reset();

    /** @psalm-suppress UnresolvableInclude Имена файлов заданы в списке блоков выше. */
    require __DIR__ . '/' . $file;
}

if (ManualTestReport::globalReport()) {
    exit(1);
}
