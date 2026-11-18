<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Manual\Support;

use MaxMessenger\Uploader\BaseUploader;
use MaxMessenger\Uploader\Contract\ContentInterface;
use MaxMessenger\Uploader\DTO\Progress;
use MaxMessenger\Uploader\DTO\UploadEventType;
use MaxMessenger\Uploader\MaxUploader;

use function intdiv;
use function max;
use function sprintf;
use function usleep;

/**
 * Наблюдает за событиями и прогрессом одной загрузки.
 */
final class UploadMonitor
{
    private bool $delayApplied = false;
    /**
     * @var positive-int|null
     */
    private ?int $delayFragment = null;
    /**
     * @var non-negative-int
     */
    private int $delayMilliseconds = 0;
    /**
     * @var array<string, positive-int>
     */
    private array $eventCounts = [];
    private ?Progress $lastProgress = null;

    /**
     * Проверяет попытки, события и завершающий прогресс загрузки.
     *
     * @param BaseUploader $uploader Загрузчик после выполнения операции.
     * @param ContentInterface $content Загруженное содержимое.
     * @param non-empty-string $check Название проверки для отчёта.
     */
    public function check(BaseUploader $uploader, ContentInterface $content, string $check): void
    {
        if ($uploader->getLastUploadAttempts() < 1) {
            ManualTestReport::fail($check, 'Не сохранено число попыток загрузки.');
        }
        $event = $uploader instanceof MaxUploader ? UploadEventType::SendFragment : UploadEventType::Send;
        $sendEvents = $this->getEventCount($event);
        $lastProgress = $this->getLastProgress();
        if ($sendEvents < 1) {
            ManualTestReport::fail($check, 'Не получено событие отправки данных.');
        }
        if ($lastProgress === null || $lastProgress->bytesSent !== $lastProgress->fragmentLength) {
            ManualTestReport::fail($check, 'Нет завершающего уведомления о прогрессе.');
        }
        if ($uploader instanceof MaxUploader) {
            if ($lastProgress !== null
                && $lastProgress->fragmentOffset + $lastProgress->bytesSent !== $content->getSize()
            ) {
                ManualTestReport::fail($check, 'Итоговое смещение прогресса не равно размеру файла.');
            }
            $fragmentLength = max($uploader->fragmentLength, (int) ($content->getSize() / 900), 32768 * 2);
            if ($fragmentLength < $content->getSize()) {
                $fragmentLength = (intdiv($fragmentLength - 1, 32768) + 1) * 32768;
            }
            $expectedSendEvents = intdiv($content->getSize() - 1, $fragmentLength) + 1;
            if ($sendEvents < $expectedSendEvents
                || ($uploader->getLastUploadAttempts() === 1 && $sendEvents !== $expectedSendEvents)
            ) {
                ManualTestReport::fail($check, sprintf(
                    'Ожидалось фрагментов: %d; событий отправки: %d.',
                    $expectedSendEvents,
                    $sendEvents,
                ));
            }
        }
        if ($this->delayMilliseconds > 0) {
            if (!$this->delayApplied || $this->getEventCount(UploadEventType::Timeout) !== 1) {
                ManualTestReport::fail($check, 'Ожидался один таймаут на заданном фрагменте.');
            }
            $retries = $this->getEventCount(UploadEventType::Resume) + $this->getEventCount(UploadEventType::Reset);
            if ($uploader->getLastUploadAttempts() !== 2 || $retries !== 1) {
                ManualTestReport::fail($check, 'После таймаута ожидалась одна повторная попытка загрузки.');
            }
        }
        Console::console()->text(sprintf(
            'Размер: %d байт; попыток загрузки: %d.',
            $content->getSize(),
            $uploader->getLastUploadAttempts(),
        ));
    }

    /**
     * Однократно задерживает чтение указанного фрагмента для проверки таймаута транспорта.
     *
     * @param positive-int $fragment Номер фрагмента, начиная с единицы.
     * @param positive-int $milliseconds Продолжительность задержки в миллисекундах.
     */
    public function delayFragmentOnce(int $fragment, int $milliseconds): void
    {
        $this->delayFragment = $fragment;
        $this->delayMilliseconds = $milliseconds;
        $this->delayApplied = false;
    }

    /**
     * @param UploadEventType $event Тип события загрузки.
     * @return non-negative-int Количество полученных событий указанного типа.
     */
    public function getEventCount(UploadEventType $event): int
    {
        return $this->eventCounts[$event->name] ?? 0;
    }

    /**
     * @return Progress|null Последнее уведомление о прогрессе или null, если уведомлений не было.
     */
    public function getLastProgress(): ?Progress
    {
        return $this->lastProgress;
    }

    /**
     * Выводит событие загрузки и увеличивает его счётчик.
     *
     * @param UploadEventType $event Полученное событие.
     */
    public function onEvent(UploadEventType $event): void
    {
        Console::console()->text('Событие: ' . $event->name);
        $this->eventCounts[$event->name] = ($this->eventCounts[$event->name] ?? 0) + 1;
    }

    /**
     * Сохраняет снимок прогресса загрузки.
     *
     * @param Progress $progress Полученное уведомление о прогрессе.
     */
    public function onProgress(Progress $progress): void
    {
        $this->lastProgress = clone $progress;
        if (!$this->delayApplied
            && $this->delayFragment !== null
            && $this->getEventCount(UploadEventType::SendFragment) === $this->delayFragment
        ) {
            $this->delayApplied = true;
            Console::text('Задержка чтения фрагмента %d на %d мс.', $this->delayFragment, $this->delayMilliseconds);
            usleep($this->delayMilliseconds * 1000);
        }
    }
}
