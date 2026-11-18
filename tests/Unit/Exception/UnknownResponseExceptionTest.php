<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit\Exception;

use Codeception\Test\Unit;
use MaxMessenger\Bot\HttpClient\HttpRequest;
use MaxMessenger\Bot\HttpClient\HttpResponse;
use MaxMessenger\Bot\Model\Response\Error;
use MaxMessenger\Uploader\Exception\UnknownResponseErrorException;
use MaxMessenger\Uploader\Exception\UnknownResponseFormatException;

final class UnknownResponseExceptionTest extends Unit
{
    public function testErrorExceptionKeepsBody(): void
    {
        $request = new HttpRequest('POST', 'https://upload.test/file');
        $response = new HttpResponse(200, 'text/html', '<html><body>Upload failed</body></html>');
        $e = new UnknownResponseErrorException($request, $response);

        self::assertSame('Unexpected upload response.', $e->getMessage());
        self::assertSame(200, $e->getCode());
        self::assertSame($request, $e->getRequest());
        self::assertSame($response, $e->getResponse());
        self::assertSame('<html><body>Upload failed</body></html>', $e->getResponse()->getBody());
        self::assertNull($e->getError());
    }

    public function testErrorExceptionKeepsParsedError(): void
    {
        $request = new HttpRequest('POST', 'https://upload.test/file');
        $response = new HttpResponse(200, 'application/json', '{"code":"upload.error","message":"Upload failed"}');
        $error = Error::newFromData(['code' => 'upload.error', 'message' => 'Upload failed']);
        $e = new UnknownResponseErrorException($request, $response, $error);

        self::assertSame('Upload failed', $e->getMessage());
        self::assertSame($error, $e->getError());
        self::assertSame($error, $e->error);
    }

    public function testFormatExceptionKeepsData(): void
    {
        $e = new UnknownResponseFormatException(['foo' => 'bar']);

        self::assertSame('Unknown data format.', $e->getMessage());
        self::assertSame(['foo' => 'bar'], $e->getData());
    }
}
