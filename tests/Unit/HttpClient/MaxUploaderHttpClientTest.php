<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit\HttpClient;

use Codeception\Test\Unit;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\Http\BadRequestException;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\Http\OkException;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\JsonDecodeException;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\UnexpectedContentTypeException;
use MaxMessenger\Bot\HttpClient\HttpResponse;
use MaxMessenger\Uploader\DTO\ResumableUploadInfo;
use MaxMessenger\Uploader\DTO\ResumableUploadStatus;
use MaxMessenger\Uploader\Exception\StreamLogicException;
use MaxMessenger\Uploader\Exception\UnknownResponseErrorException;
use MaxMessenger\Uploader\HttpClient\Body\StreamBody;
use MaxMessenger\Uploader\HttpClient\MaxUploaderHttpClient;
use MaxMessenger\Uploader\HttpClient\Stream\MultipartFormStream;
use MaxMessenger\Uploader\HttpClient\Stream\StringStream;
use MaxMessenger\Uploader\Tests\Support\Fake\FakeHttpClient;

use function strlen;

final class MaxUploaderHttpClientTest extends Unit
{
    private const JSON = 'application/json; charset=utf-8';
    private const URL = 'https://upload.test/file';

    private FakeHttpClient $httpClient;

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function apiErrors(): iterable
    {
        yield 'legacy JSON' => ['{"error_code":"upload.error","error_data":"Upload failed"}', true];
        yield 'standard JSON' => ['{"code":"upload.error","message":"Upload failed"}', true];
        yield 'legacy raw' => ['{"error_code":"upload.error","error_data":"Upload failed"}', false];
        yield 'standard raw' => ['{"code":"upload.error","message":"Upload failed"}', false];
    }

    public function testGetResumableUploadInfo(): void
    {
        $client = $this->createClient([new HttpResponse(200, 'text/plain', '0-99/200')]);

        $info = $client->getResumableUploadInfo(self::URL);

        self::assertEquals(new ResumableUploadInfo(99, 200), $info);
        self::assertSame('GET', $this->httpClient->requests[0]->getMethod());
        self::assertSame(self::URL, $this->httpClient->requests[0]->getUrl());
    }

    public function testGetResumableUploadInfoInvalidResponseMakesEndpointUnavailable(): void
    {
        $client = $this->createClient([new HttpResponse(200, 'text/plain', 'garbage')]);

        self::assertSame(ResumableUploadStatus::UploadEndpointUnavailable, $client->getResumableUploadInfo(self::URL));
    }

    public function testGetResumableUploadInfoNoContentKeepsLastOffset(): void
    {
        $client = $this->createClient([new HttpResponse(204)]);

        self::assertSame(ResumableUploadStatus::ContinueFromLastOffset, $client->getResumableUploadInfo(self::URL));
    }

    public function testGetResumableUploadInfoNotFoundStartsFromBeginning(): void
    {
        $client = $this->createClient([new HttpResponse(404)]);

        self::assertSame(ResumableUploadStatus::StartFromBeginning, $client->getResumableUploadInfo(self::URL));
    }

    /**
     * @dataProvider apiErrors
     */
    public function testPostMultipartFormApiErrorInResponseThrowsException(string $body, bool $json): void
    {
        $client = $this->createClient([
            new HttpResponse(200, $json ? self::JSON : 'text/plain', $body),
        ]);

        try {
            $this->postMultipartForm($client, $json);
            self::fail('Expected exception was not thrown');
        } catch (UnknownResponseErrorException $e) {
            self::assertSame('upload.error', $e->getError()?->getCode());
            self::assertSame('Upload failed', $e->getError()?->getMessage());
            self::assertSame($this->httpClient->requests[0], $e->getRequest());
            self::assertSame(200, $e->getResponse()->getHttpCode());
        }
    }

    public function testPostMultipartFormEmptyJsonThrowsException(): void
    {
        $client = $this->createClient([new HttpResponse(200, self::JSON, '[]')]);

        $this->expectException(UnknownResponseErrorException::class);
        $this->postMultipartForm($client, true);
    }

    public function testPostMultipartFormHttpErrorThrowsException(): void
    {
        $client = $this->createClient([
            new HttpResponse(400, self::JSON, '{"error_code":"bad.request","error_data":"Bad file"}'),
        ]);

        try {
            $this->postMultipartForm($client, false);
            self::fail('Expected exception was not thrown');
        } catch (BadRequestException $e) {
            self::assertSame('bad.request', $e->getError()?->getCode());
        }
    }

    public function testPostMultipartFormInvalidApiErrorThrowsException(): void
    {
        $client = $this->createClient([new HttpResponse(200, self::JSON, '{"error_code":1,"error_data":2}')]);

        try {
            $this->postMultipartForm($client, true);
            self::fail('Expected exception was not thrown');
        } catch (UnknownResponseErrorException $e) {
            self::assertNull($e->getError());
            self::assertSame('{"error_code":1,"error_data":2}', $e->getResponse()->getBody());
            self::assertSame($this->httpClient->requests[0], $e->getRequest());
        }
    }

    public function testPostMultipartFormInvalidJsonThrowsException(): void
    {
        $client = $this->createClient([new HttpResponse(200, self::JSON, '{')]);

        $this->expectException(JsonDecodeException::class);
        $this->postMultipartForm($client, true);
    }

    public function testPostMultipartFormJson(): void
    {
        $client = $this->createClient([new HttpResponse(200, self::JSON, '{"fileId":1,"token":"t"}')]);

        $result = $this->postMultipartForm($client, true);

        self::assertSame(['fileId' => 1, 'token' => 't'], $result);

        $request = $this->httpClient->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::URL, $request->getUrl());
        self::assertSame(['Accept' => self::JSON], $request->getHeaders());
        self::assertSame(1000, $request->getTimeout());
        self::assertSame(10, $request->getLowSpeedLimit());
        self::assertSame(20, $request->getLowSpeedTime());

        $body = $request->getBody();
        self::assertInstanceOf(StreamBody::class, $body);
        self::assertStringStartsWith('multipart/form-data; boundary=', $body->getContentType());
        self::assertStringContainsString('filename="a.txt"', $this->httpClient->bodies[0]);
        self::assertStringContainsString("\r\n\r\nabc\r\n", $this->httpClient->bodies[0]);
    }

    public function testPostMultipartFormRaw(): void
    {
        $client = $this->createClient([new HttpResponse(200, 'text/html', '<retval>1</retval>')]);

        $result = $this->postMultipartForm($client, false);

        self::assertNull($result);
        self::assertSame([], $this->httpClient->requests[0]->getHeaders());
    }

    public function testPostMultipartFormUnexpectedContentTypeThrowsException(): void
    {
        $client = $this->createClient([new HttpResponse(200, 'text/html', '{}')]);

        $this->expectException(UnexpectedContentTypeException::class);
        $this->postMultipartForm($client, true);
    }

    public function testPostResumableUploadEmptySizeThrowsException(): void
    {
        $client = $this->createClient();

        $this->expectException(StreamLogicException::class);
        /** @psalm-suppress InvalidArgument Проверяется отклонение недопустимого размера. */
        $client->postResumableUpload(
            self::URL,
            new StringStream('abc', 0, 3),
            'a.txt',
            'text/plain',
            0,
            0,
            0,
            null,
            null,
            false,
        );
    }

    public function testPostResumableUploadFinalFragment(): void
    {
        $client = $this->createClient([new HttpResponse(200, self::JSON, '{"fileId":1,"token":"t"}')]);

        $result = $client->postResumableUpload(
            self::URL,
            new StringStream('cd', 0, 2),
            'тест файл.txt',
            'text/plain',
            4,
            2,
            0,
            null,
            null,
            true,
        );

        self::assertSame(['fileId' => 1, 'token' => 't'], $result);

        $request = $this->httpClient->requests[0];
        self::assertSame([
            'Content-Disposition' => 'attachment; filename=%D1%82%D0%B5%D1%81%D1%82%20%D1%84%D0%B0%D0%B9%D0%BB.txt',
            'Content-Length' => '2',
            'Content-Range' => 'bytes 2-3/4',
            'Accept' => self::JSON,
        ], $request->getHeaders());
        self::assertSame('text/plain', $request->getBody()?->getContentType());
        self::assertSame('cd', $this->httpClient->bodies[0]);
    }

    public function testPostResumableUploadIntermediateFragment(): void
    {
        $client = $this->createClient([
            new HttpResponse(201, 'text/plain', '0-1/4'),
            new HttpResponse(201),
        ]);
        $stream = new StringStream('ab', 0, 2);

        $raw = $client->postResumableUpload(self::URL, $stream, 'a.txt', 'text/plain', 4, 0, 0, null, null, false);
        $stream->seek(0);
        $json = $client->postResumableUpload(self::URL, $stream, 'a.txt', 'text/plain', 4, 0, 0, null, null, true);

        self::assertEquals(new ResumableUploadInfo(1, 4), $raw);
        self::assertSame([], $json);
        self::assertSame('bytes 0-1/4', $this->httpClient->requests[0]->getHeaders()['Content-Range']);
    }

    public function testPostResumableUploadInvalidRangeThrowsException(): void
    {
        $client = $this->createClient([new HttpResponse(201, 'text/plain', 'garbage')]);

        $this->expectException(UnknownResponseErrorException::class);
        $client->postResumableUpload(
            self::URL,
            new StringStream('ab', 0, 2),
            'a.txt',
            'text/plain',
            4,
            0,
            0,
            null,
            null,
            false,
        );
    }

    public function testPostResumableUploadUnexpectedHttpCodeThrowsException(): void
    {
        $client = $this->createClient([new HttpResponse(200, 'text/plain', '0-1/4')]);

        $this->expectException(OkException::class);
        $client->postResumableUpload(
            self::URL,
            new StringStream('ab', 0, 2),
            'a.txt',
            'text/plain',
            4,
            0,
            0,
            null,
            null,
            false,
        );
    }

    /**
     * @param list<HttpResponse> $upload
     */
    private function createClient(array $upload = []): MaxUploaderHttpClient
    {
        $this->httpClient = new FakeHttpClient($upload);

        return new MaxUploaderHttpClient($this->httpClient);
    }

    private function postMultipartForm(MaxUploaderHttpClient $client, bool $json): ?array
    {
        $stream = new MultipartFormStream(new StringStream('abc', 0, strlen('abc')), 'a.txt', 'text/plain');

        return $client->postMultipartForm(
            self::URL,
            $stream,
            $stream->getContentType(),
            1000,
            10,
            20,
            $json,
        );
    }
}
