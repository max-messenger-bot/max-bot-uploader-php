<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit;

use Codeception\Test\Unit;
use MaxMessenger\Bot\HttpClient\Exception\TimeoutException;
use MaxMessenger\Bot\HttpClient\HttpResponse;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\Model\Response\UploadEndpoint;
use MaxMessenger\Uploader\Content\StringContent;
use MaxMessenger\Uploader\Contract\MaxUploaderHttpClientInterface;
use MaxMessenger\Uploader\DTO\Progress;
use MaxMessenger\Uploader\DTO\UploadEventType;
use MaxMessenger\Uploader\Exception\UnknownResponseErrorException;
use MaxMessenger\Uploader\Exception\UnknownResponseFormatException;
use MaxMessenger\Uploader\HttpClient\MaxUploaderHttpClient;
use MaxMessenger\Uploader\MaxMultipartFormUploader;
use MaxMessenger\Uploader\Tests\Support\Fake\FakeContent;
use MaxMessenger\Uploader\Tests\Support\Fake\FakeHttpClient;
use MaxMessenger\Uploader\Tests\Support\Fake\FakeMaxHttpClient;

use function end;

final class MaxMultipartFormUploaderTest extends Unit
{
    private const ENDPOINT = ['url' => 'https://upload.test/form', 'token' => 'upload-token'];
    private const JSON = 'application/json; charset=utf-8';

    private FakeMaxHttpClient $apiHttpClient;
    /**
     * @var list<UploadEventType>
     */
    private array $events = [];
    private FakeHttpClient $uploaderHttpClient;

    public function testEndpointWithoutTokenThrowsException(): void
    {
        $uploader = $this->createUploader([], [['url' => 'https://upload.test/form']]);

        $this->expectException(UnknownResponseFormatException::class);
        $uploader->uploadVideo(new StringContent('video', 'a.mp4'));
    }

    public function testNonSeekableStreamIsNotRetriedAfterTimeout(): void
    {
        $uploader = $this->createUploader([FakeHttpClient::timeout()]);

        $this->expectException(TimeoutException::class);
        $uploader->uploadAudio(new FakeContent('audio', false));
    }

    public function testRetriesAfterTimeoutWithSameEndpoint(): void
    {
        $uploader = $this->createUploader([
            FakeHttpClient::timeout(),
            new HttpResponse(200, 'text/html', '<retval>1</retval>'),
        ]);

        self::assertSame('upload-token', $uploader->uploadAudio(new FakeContent('audio', true)));
        self::assertSame(2, $uploader->getLastUploadAttempts());
        self::assertCount(1, $this->apiHttpClient->posts);
        self::assertCount(2, $this->uploaderHttpClient->requests);
        self::assertSame(
            [UploadEventType::UrlRequest, UploadEventType::Send, UploadEventType::Timeout, UploadEventType::Send],
            $this->events,
        );
    }

    public function testRetryAttemptsExhaustedThrowsException(): void
    {
        $uploader = $this
            ->createUploader([FakeHttpClient::timeout(), FakeHttpClient::timeout()])
            ->setRetryAttempts(1);

        try {
            $uploader->uploadAudio(new StringContent('audio', 'a.mp3'));
            self::fail('Expected exception was not thrown');
        } catch (TimeoutException) {
            self::assertSame(2, $uploader->getLastUploadAttempts());
            self::assertCount(2, $this->uploaderHttpClient->requests);
        }
    }

    public function testUnexpectedResponseThrowsException(): void
    {
        $uploader = $this->createUploader([new HttpResponse(200, 'text/html', '<retval>0</retval>')]);

        $this->expectException(UnknownResponseErrorException::class);
        $uploader->uploadVideo(new StringContent('video', 'a.mp4'));
    }

    public function testUploadAudio(): void
    {
        $progress = [];
        $uploader = $this
            ->createUploader([new HttpResponse(200, 'text/html', '<retval>1</retval>')])
            ->setProgressCallback(static function (Progress $p) use (&$progress): void {
                $progress[] = $p;
            })
            ->setTimeout(5000)
            ->setLowSpeedLimit(100)
            ->setLowSpeedTime(30);

        $token = $uploader->uploadAudio(new StringContent('audio-data', 'dir/a.mp3'));

        self::assertSame('upload-token', $token);
        self::assertSame([['path' => '/uploads', 'query' => ['type' => 'audio']]], $this->apiHttpClient->posts);
        self::assertSame([UploadEventType::UrlRequest, UploadEventType::Send], $this->events);
        self::assertEquals(UploadEndpoint::newFromData(self::ENDPOINT), $uploader->getLastUploadEndpoint());

        $request = $this->uploaderHttpClient->requests[0];
        self::assertSame('https://upload.test/form', $request->getUrl());
        self::assertSame(5000, $request->getTimeout());
        self::assertSame(100, $request->getLowSpeedLimit());
        self::assertSame(30, $request->getLowSpeedTime());
        self::assertStringContainsString('filename="a.mp3"', $this->uploaderHttpClient->bodies[0]);
        self::assertStringContainsString("\r\n\r\naudio-data\r\n", $this->uploaderHttpClient->bodies[0]);

        $last = end($progress);
        self::assertInstanceOf(Progress::class, $last);
        self::assertSame(0, $last->fragmentOffset);
        self::assertSame($last->size, $last->fragmentLength);
        self::assertSame($last->size, $last->bytesSent);
    }

    public function testUploadFile(): void
    {
        $uploader = $this->createUploaderWithData(['fileId' => 7, 'token' => 'file-token']);

        self::assertSame('file-token', $uploader->uploadFile(new StringContent('file', 'a.txt')));
    }

    public function testUploadFileEx(): void
    {
        $uploader = $this->createUploader([new HttpResponse(200, self::JSON, '{"fileId":7,"token":"file-token"}')]);

        $file = $uploader->uploadFileEx(new StringContent('file', 'a.txt'));

        self::assertSame(7, $file->getFileId());
        self::assertSame('file-token', $file->getToken());
        self::assertSame(['Accept' => self::JSON], $this->uploaderHttpClient->requests[0]->getHeaders());
    }

    public function testUploadFileInvalidResponseThrowsException(): void
    {
        $uploader = $this->createUploaderWithData(['token' => 'file-token']);

        $this->expectException(UnknownResponseFormatException::class);
        $uploader->uploadFile(new StringContent('file', 'a.txt'));
    }

    public function testUploadImage(): void
    {
        $uploader = $this->createUploaderWithData(['photos' => ['a' => ['token' => 'image-token']]]);

        self::assertSame('image-token', $uploader->uploadImage(new StringContent('image', 'a.png')));
        self::assertSame(['type' => 'image'], $this->apiHttpClient->posts[0]['query']);
    }

    public function testUploadImageWithoutPhotosThrowsException(): void
    {
        $uploader = $this->createUploaderWithData(['photos' => []]);

        $this->expectException(UnknownResponseFormatException::class);
        $uploader->uploadImage(new StringContent('image', 'a.png'));
    }

    public function testUsesGivenEndpointOnce(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(200, 'text/html', '<retval>1</retval>'),
            new HttpResponse(200, 'text/html', '<retval>1</retval>'),
        ]);
        $uploader->setUploadEndpoint(UploadEndpoint::newFromData([
            'url' => 'https://upload.test/given',
            'token' => 'given',
        ]));

        self::assertSame('given', $uploader->uploadVideo(new StringContent('video', 'a.mp4')));
        self::assertSame(1, $uploader->getLastUploadAttempts());
        self::assertSame([], $this->apiHttpClient->posts);
        self::assertNull($uploader->uploadEndpoint);

        self::assertSame('upload-token', $uploader->uploadVideo(new StringContent('video', 'a.mp4')));
        self::assertSame(1, $uploader->getLastUploadAttempts());
        self::assertCount(1, $this->apiHttpClient->posts);
    }

    public function testUsesGivenUploadClient(): void
    {
        $uploadClient = $this->createMock(MaxUploaderHttpClientInterface::class);
        $apiClient = new MaxApiClient('test-token', httpClient: new FakeMaxHttpClient());
        $uploader = new MaxMultipartFormUploader($apiClient, $uploadClient);

        self::assertSame($apiClient, $uploader->getApiClient());
        self::assertSame($uploadClient, $uploader->getMaxUploaderHttpClient());
    }

    /**
     * @param list<HttpResponse|TimeoutException> $uploadResponses
     * @param list<array>|null $endpoints
     */
    private function createUploader(array $uploadResponses, ?array $endpoints = null): MaxMultipartFormUploader
    {
        $this->apiHttpClient = new FakeMaxHttpClient($endpoints ?? [self::ENDPOINT]);
        $this->uploaderHttpClient = new FakeHttpClient($uploadResponses);
        $this->events = [];

        $uploader = new MaxMultipartFormUploader(
            new MaxApiClient('test-token', httpClient: $this->apiHttpClient),
            new MaxUploaderHttpClient($this->uploaderHttpClient),
        );

        return $uploader->setEventCallback(function (UploadEventType $event): void {
            $this->events[] = $event;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createUploaderWithData(array $data): MaxMultipartFormUploader
    {
        $uploadClient = $this->createMock(MaxUploaderHttpClientInterface::class);
        $uploadClient->method('postMultipartForm')->willReturn($data);
        $this->apiHttpClient = new FakeMaxHttpClient([self::ENDPOINT]);

        return new MaxMultipartFormUploader(
            new MaxApiClient('test-token', httpClient: $this->apiHttpClient),
            $uploadClient,
        );
    }
}
