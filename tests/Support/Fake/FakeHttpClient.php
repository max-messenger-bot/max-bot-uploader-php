<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Support\Fake;

use LogicException;
use MaxMessenger\Bot\Contract\Http\HttpClientInterface;
use MaxMessenger\Bot\Contract\Http\HttpRequestInterface;
use MaxMessenger\Bot\Contract\Http\HttpResponseInterface;
use MaxMessenger\Bot\Contract\Http\StreamInterface;
use MaxMessenger\Bot\HttpClient\Exception\TimeoutException;
use MaxMessenger\Bot\HttpClient\HttpRequest;
use RuntimeException;
use Throwable;

use function array_shift;
use function count;
use function in_array;

/**
 * Фейковый HTTP-клиент для герметичных тестов: запоминает запросы, читает их тела
 * и по очереди возвращает заданные ответы или выбрасывает заданные исключения.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /**
     * @var list<string> Тела запросов, прочитанные при отправке.
     */
    public array $bodies = [];
    /**
     * @var list<HttpRequestInterface>
     */
    public array $requests = [];
    /**
     * @var list<non-negative-int> Номера запросов (с нуля), на которые нужно ответить, не читая тело.
     */
    public array $unreadBodies = [];

    /**
     * @param list<HttpResponseInterface|Throwable> $responses Ответы или исключения: по одному на запрос.
     */
    public function __construct(public array $responses = []) {}

    public function request(HttpRequestInterface $request): HttpResponseInterface
    {
        $index = count($this->requests);
        $this->requests[] = $request;

        $body = $request->getBody()?->getBody();
        if (in_array($index, $this->unreadBodies, true)) {
            $this->bodies[] = '';
        } else {
            $this->bodies[] = $body instanceof StreamInterface ? $body->getContents() : (string) $body;
        }

        $response = array_shift($this->responses) ?? throw new LogicException('No response queued.');
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    /**
     * Создаёт исключение таймаута запроса.
     */
    public static function timeout(): TimeoutException
    {
        return new TimeoutException(new RuntimeException('Timeout.'), new HttpRequest('POST', 'https://upload.test'));
    }
}
