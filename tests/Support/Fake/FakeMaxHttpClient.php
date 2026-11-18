<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Support\Fake;

use Closure;
use LogicException;
use MaxMessenger\Bot\Contract\Http\HttpClientInterface;
use MaxMessenger\Bot\Contract\MaxHttpClientInterface;

use function array_shift;

/**
 * Фейковый клиент API для герметичных тестов: запоминает POST-запросы
 * и по очереди возвращает заданные ответы.
 */
final class FakeMaxHttpClient implements MaxHttpClientInterface
{
    /**
     * @var list<array{path: string, query: array|null}>
     */
    public array $posts = [];

    /**
     * @param list<array> $responses Ответы на POST-запросы: по одному на запрос.
     */
    public function __construct(public array $responses = []) {}

    public function delete(string $path, ?array $query = null): array
    {
        throw new LogicException('Not used in tests.');
    }

    public function get(string $path, ?array $query = null): array
    {
        throw new LogicException('Not used in tests.');
    }

    public function getHttpClient(): HttpClientInterface
    {
        throw new LogicException('Not used in tests.');
    }

    public function getHttpClientForUpdates(): HttpClientInterface
    {
        throw new LogicException('Not used in tests.');
    }

    public function getHttpClientForUploader(): HttpClientInterface
    {
        throw new LogicException('Not used in tests.');
    }

    public function getUpdates(string $path, ?array $query = null, ?int $timeout = null): array
    {
        throw new LogicException('Not used in tests.');
    }

    public function patch(string $path, object $body, ?array $query = null): array
    {
        throw new LogicException('Not used in tests.');
    }

    public function post(string $path, ?object $body, ?array $query = null): array
    {
        $this->posts[] = ['path' => $path, 'query' => $query];

        return array_shift($this->responses) ?? throw new LogicException('No response queued.');
    }

    public function put(string $path, object $body, ?array $query = null): array
    {
        throw new LogicException('Not used in tests.');
    }

    public function setExceptionLogger(?Closure $exceptionLogger): static
    {
        return $this;
    }

    public function setVersion(?string $version): static
    {
        return $this;
    }
}
