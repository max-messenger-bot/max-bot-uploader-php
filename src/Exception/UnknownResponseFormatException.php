<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Exception;

/**
 * Неизвестный формат ответа.
 *
 * Возникает, если полученные данные имеют неизвестный или недопустимый формат.
 */
final class UnknownResponseFormatException extends UploaderException
{
    /**
     * @param array $data Данные ответа недопустимого формата.
     */
    public function __construct(
        public readonly array $data,
    ) {
        parent::__construct('Unknown data format.');
    }

    /**
     * @return array Некорректные данные, вызвавшие исключение.
     */
    public function getData(): array
    {
        return $this->data;
    }
}
