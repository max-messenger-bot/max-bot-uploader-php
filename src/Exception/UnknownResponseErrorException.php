<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Exception;

use MaxMessenger\Bot\Contract\Http\HttpRequestInterface;
use MaxMessenger\Bot\Contract\Http\HttpResponseInterface;
use MaxMessenger\Bot\Model\Response\Error;

/**
 * Неизвестная ошибка в ответе.
 *
 * Возникает при неизвестной ошибке API.
 */
final class UnknownResponseErrorException extends UploaderException
{
    /**
     * @param HttpRequestInterface $request Запрос, на который получен ответ.
     * @param HttpResponseInterface $response Ответ сервера.
     * @param Error|null $error Разобранное описание ошибки или `null`, если его получить не удалось.
     */
    public function __construct(
        private readonly HttpRequestInterface $request,
        private readonly HttpResponseInterface $response,
        public readonly ?Error $error = null,
    ) {
        parent::__construct(
            $this->error?->getMessage() ?? 'Unexpected upload response.',
            $this->response->getHttpCode(),
        );
    }

    /**
     * @return Error|null Разобранное описание ошибки или `null`, если его получить не удалось.
     */
    public function getError(): ?Error
    {
        return $this->error;
    }

    /**
     * @return HttpRequestInterface Запрос, на который получен ответ.
     */
    public function getRequest(): HttpRequestInterface
    {
        return $this->request;
    }

    /**
     * @return HttpResponseInterface Ответ сервера.
     */
    public function getResponse(): HttpResponseInterface
    {
        return $this->response;
    }
}
