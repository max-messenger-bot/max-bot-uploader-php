<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit;

use Codeception\Test\Unit;
use MaxMessenger\Bot\HttpClient\Exception\HttpResponse\Http\OkException;
use MaxMessenger\Bot\HttpClient\Exception\TimeoutException;
use MaxMessenger\Bot\HttpClient\HttpResponse;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\Model\Response\UploadEndpoint;
use MaxMessenger\Uploader\Content\StreamContent;
use MaxMessenger\Uploader\Content\StringContent;
use MaxMessenger\Uploader\DTO\Progress;
use MaxMessenger\Uploader\DTO\UploadEventType;
use MaxMessenger\Uploader\Exception\UnexpectedUploadRangeException;
use MaxMessenger\Uploader\Exception\UnknownResponseErrorException;
use MaxMessenger\Uploader\Exception\UploadEndpointException;
use MaxMessenger\Uploader\HttpClient\MaxUploaderHttpClient;
use MaxMessenger\Uploader\MaxUploader;
use MaxMessenger\Uploader\Tests\Support\Fake\FakeContent;
use MaxMessenger\Uploader\Tests\Support\Fake\FakeHttpClient;
use MaxMessenger\Uploader\Tests\Support\Fake\FakeMaxHttpClient;

use function array_map;
use function end;
use function fclose;
use function proc_close;
use function proc_open;
use function str_repeat;
use function strlen;

final class MaxUploaderTest extends Unit
{
    private const ENDPOINT = ['url' => 'https://upload.test/resumable', 'token' => 'upload-token'];
    private const JSON = 'application/json; charset=utf-8';
    private const MIN_FRAGMENT = 65536;
    private const SIZE = 150000;

    private FakeMaxHttpClient $apiHttpClient;
    /**
     * @var list<UploadEventType>
     */
    private array $events = [];
    private FakeHttpClient $httpClient;

    public function testAcceptsResponseBeyondSentFragment(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(201, 'text/plain', '0-131071/' . self::SIZE),
            new HttpResponse(201, 'text/plain', '0-131071/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
        ])->setFragmentLength(1);

        self::assertSame('upload-token', $uploader->uploadVideo($this->createContent()));
        self::assertSame(
            ['bytes 0-65535/150000', 'bytes 65536-131071/150000', 'bytes 131072-149999/150000'],
            $this->contentRanges(),
        );
    }

    public function testFirstAttemptResetsOffsetFromPreviousUpload(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
        ], [self::ENDPOINT, self::ENDPOINT]);
        $uploader->smartResume = false;

        self::assertSame('upload-token', $uploader->uploadVideo($this->createContent()));
        self::assertSame('upload-token', $uploader->uploadVideo($this->createContent()));
        self::assertSame(['bytes 0-149999/150000', 'bytes 0-149999/150000'], $this->contentRanges());
    }

    public function testInfoTotalMismatchRejectsProvidedEndpoint(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(200, 'text/plain', '0-99/999'),
        ]);
        $uploader->setUploadEndpoint(UploadEndpoint::newFromData([
            'url' => 'https://upload.test/old',
            'token' => 'old',
        ]), false);

        $this->expectException(UploadEndpointException::class);
        try {
            $uploader->uploadVideo($this->createContent());
        } finally {
            self::assertSame([], $this->apiHttpClient->posts);
            self::assertSame([UploadEventType::FileInfoRequest], $this->events);
        }
    }

    public function testInvalidUploadRangeThrowsException(): void
    {
        $uploader = $this->createUploader([new HttpResponse(200, 'text/plain', 'garbage')]);

        $this->expectException(UnknownResponseErrorException::class);
        $uploader->uploadVideo($this->createContent());
    }

    public function testNonSeekableSourceStartsFromBeginningForProvidedEndpoint(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "abcdefghij");'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        try {
            $content = new StreamContent($pipes[1], 'a.bin', 10);
            self::assertFalse($content->isSeekable());
            $uploader = $this->createUploader([new HttpResponse(200, 'text/plain', '0-9/10')]);
            $uploader->setUploadEndpoint(
                UploadEndpoint::newFromData(['url' => 'https://upload.test/old', 'token' => 'old']),
                false,
            );

            self::assertSame('old', $uploader->uploadVideo($content));
            self::assertSame(['bytes 0-9/10'], $this->contentRanges());
            self::assertSame(['abcdefghij'], $this->httpClient->bodies);
            self::assertSame([UploadEventType::SendFragment], $this->events);
            self::assertSame([], $this->apiHttpClient->posts);
            self::assertCount(1, $this->httpClient->requests);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function testProvidedEndpointRestartsAfterUnexpected201(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(201, 'text/plain', '0-149999/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
        ])->setRetryAttempts(3);
        $uploader->setUploadEndpoint(UploadEndpoint::newFromData([
            'url' => 'https://upload.test/old',
            'token' => 'old',
        ]));

        self::assertSame('old', $uploader->uploadVideo($this->createContent()));
        self::assertSame([], $this->apiHttpClient->posts);
        self::assertCount(2, $this->httpClient->requests);
        self::assertSame(
            [
                UploadEventType::SendFragment,
                UploadEventType::Error,
                UploadEventType::Reset,
                UploadEventType::SendFragment,
            ],
            $this->events,
        );
        self::assertSame(['bytes 0-149999/150000', 'bytes 0-149999/150000'], $this->contentRanges());
    }

    public function testResetWithSameEndpoint(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(204),
            new HttpResponse(200, 'text/plain', '0-' . (self::SIZE - 1) . '/' . self::SIZE),
        ]);
        $uploader->setUploadEndpoint(UploadEndpoint::newFromData([
            'url' => 'https://upload.test/old',
            'token' => 'old',
        ]), false);

        self::assertSame('old', $uploader->uploadVideo($this->createContent()));
        self::assertSame([], $this->apiHttpClient->posts);
        self::assertSame(
            [UploadEventType::FileInfoRequest, UploadEventType::Reset, UploadEventType::SendFragment],
            $this->events,
        );
        self::assertSame('bytes 0-149999/150000', $this->httpClient->requests[1]->getHeaders()['Content-Range']);
    }

    public function testResetWithSameEndpointAfterNotFoundStatus(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(404),
            new HttpResponse(200, 'text/plain', '0-' . (self::SIZE - 1) . '/' . self::SIZE),
        ]);
        $uploader->setUploadEndpoint(UploadEndpoint::newFromData([
            'url' => 'https://upload.test/old',
            'token' => 'old',
        ]), false);

        self::assertSame('old', $uploader->uploadVideo($this->createContent()));
        self::assertSame([], $this->apiHttpClient->posts);
        self::assertSame(
            [UploadEventType::FileInfoRequest, UploadEventType::Reset, UploadEventType::SendFragment],
            $this->events,
        );
        self::assertSame('https://upload.test/old', $this->httpClient->requests[1]->getUrl());
    }

    public function testRestartsWithSameEndpointAfterUnexpected200(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(200, 'text/plain', '0-65535/' . self::SIZE),
            new HttpResponse(201, 'text/plain', '0-65535/' . self::SIZE),
            new HttpResponse(201, 'text/plain', '0-131071/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
        ], [self::ENDPOINT])->setFragmentLength(1)->setRetryAttempts(1);

        self::assertSame('upload-token', $uploader->uploadVideo($this->createContent()));
        self::assertSame(2, $uploader->getLastUploadAttempts());
        self::assertCount(1, $this->apiHttpClient->posts);
        self::assertSame(
            [
                UploadEventType::UrlRequest,
                UploadEventType::SendFragment,
                UploadEventType::Error,
                UploadEventType::Reset,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
            ],
            $this->events,
        );
        self::assertSame('bytes 0-65535/150000', $this->contentRanges()[1]);
    }

    public function testRestartsWithSameEndpointAfterUnexpected201(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(201, 'text/plain', '0-149999/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
        ], [self::ENDPOINT])->setRetryAttempts(1);

        self::assertSame('upload-token', $uploader->uploadVideo($this->createContent()));
        self::assertCount(1, $this->apiHttpClient->posts);
        self::assertCount(2, $this->httpClient->requests);
        self::assertSame(
            [
                UploadEventType::UrlRequest,
                UploadEventType::SendFragment,
                UploadEventType::Error,
                UploadEventType::Reset,
                UploadEventType::SendFragment,
            ],
            $this->events,
        );
    }

    public function testResumesFromLoadedByte(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(200, 'text/plain', '0-99999/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-' . (self::SIZE - 1) . '/' . self::SIZE),
        ]);
        $uploader->setUploadEndpoint(UploadEndpoint::newFromData([
            'url' => 'https://upload.test/old',
            'token' => 'old',
        ]), false);

        self::assertSame('old', $uploader->uploadVideo($this->createContent()));
        self::assertSame('GET', $this->httpClient->requests[0]->getMethod());
        self::assertSame('https://upload.test/old', $this->httpClient->requests[0]->getUrl());
        self::assertSame(
            [UploadEventType::FileInfoRequest, UploadEventType::Resume, UploadEventType::SendFragment],
            $this->events,
        );
        self::assertSame('bytes 100000-149999/150000', $this->httpClient->requests[1]->getHeaders()['Content-Range']);
        self::assertSame(str_repeat('x', 50000), $this->httpClient->bodies[1]);
        self::assertTrue($uploader->startFromBeginning);
    }

    public function testResumesWhenAllBytesLoaded(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(200, 'text/plain', '0-' . (self::SIZE - 1) . '/' . self::SIZE),
        ]);
        $uploader->setUploadEndpoint(UploadEndpoint::newFromData([
            'url' => 'https://upload.test/old',
            'token' => 'old',
        ]), false);

        self::assertSame('old', $uploader->uploadVideo($this->createContent()));
        self::assertCount(1, $this->httpClient->requests);
        self::assertSame('GET', $this->httpClient->requests[0]->getMethod());
    }

    public function testResumesWhenOneByteRemains(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(200, 'text/plain', '0-' . (self::SIZE - 2) . '/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-' . (self::SIZE - 1) . '/' . self::SIZE),
        ]);
        $uploader->setUploadEndpoint(UploadEndpoint::newFromData([
            'url' => 'https://upload.test/old',
            'token' => 'old',
        ]), false);

        self::assertSame('old', $uploader->uploadVideo($this->createContent()));
        self::assertSame(['bytes 149999-149999/150000'], $this->contentRanges());
    }

    public function testRetriesAfterTimeoutFromLastSuccessfulFragmentWhenStatusHasNoContent(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(201, 'text/plain', '0-65535/' . self::SIZE),
            FakeHttpClient::timeout(),
            new HttpResponse(204),
            new HttpResponse(201, 'text/plain', '0-131071/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
        ])->setFragmentLength(1);

        self::assertSame('upload-token', $uploader->uploadAudio($this->createContent()));
        self::assertSame(2, $uploader->getLastUploadAttempts());
        self::assertSame(
            [
                UploadEventType::UrlRequest,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
                UploadEventType::Timeout,
                UploadEventType::FileInfoRequest,
                UploadEventType::Resume,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
            ],
            $this->events,
        );
        self::assertSame(
            [
                'bytes 0-65535/150000',
                'bytes 65536-131071/150000',
                'bytes 65536-131071/150000',
                'bytes 131072-149999/150000',
            ],
            $this->contentRanges(),
        );
    }

    public function testRetriesAfterTimeoutWithResume(): void
    {
        $uploader = $this->createUploader(
            [
                new HttpResponse(201, 'text/plain', '0-65535/' . self::SIZE),
                FakeHttpClient::timeout(),
                new HttpResponse(200, 'text/plain', '0-65535/' . self::SIZE),
                new HttpResponse(201, 'text/plain', '0-131071/' . self::SIZE),
                new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
            ],
        )->setFragmentLength(1);

        self::assertSame('upload-token', $uploader->uploadAudio($this->createContent()));
        self::assertCount(1, $this->apiHttpClient->posts);
        self::assertSame(
            [
                UploadEventType::UrlRequest,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
                UploadEventType::Timeout,
                UploadEventType::FileInfoRequest,
                UploadEventType::Resume,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
            ],
            $this->events,
        );
        self::assertSame(
            [
                'bytes 0-65535/150000',
                'bytes 65536-131071/150000',
                'bytes 65536-131071/150000',
                'bytes 131072-149999/150000',
            ],
            $this->contentRanges(),
        );
    }

    public function testRetriesTimedOutFragmentWithoutStatusRequest(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(201, 'text/plain', '0-65535/' . self::SIZE),
            FakeHttpClient::timeout(),
            new HttpResponse(201, 'text/plain', '0-131071/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
        ])->setFragmentLength(1);
        $uploader->setSmartResume(false);

        self::assertSame('upload-token', $uploader->uploadAudio($this->createContent()));
        self::assertSame(2, $uploader->getLastUploadAttempts());
        self::assertCount(1, $this->apiHttpClient->posts);
        self::assertSame(
            [
                UploadEventType::UrlRequest,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
                UploadEventType::Timeout,
                UploadEventType::Resume,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
            ],
            $this->events,
        );
        self::assertSame(
            [
                'bytes 0-65535/150000',
                'bytes 65536-131071/150000',
                'bytes 65536-131071/150000',
                'bytes 131072-149999/150000',
            ],
            $this->contentRanges(),
        );
        self::assertSame($this->httpClient->bodies[1], $this->httpClient->bodies[2]);
    }

    public function testSmartResumeDisabledRestartsFromBeginningAfterUnexpectedResponse(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(201, 'text/plain', '0-65535/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-131071/' . self::SIZE),
            new HttpResponse(201, 'text/plain', '0-65535/' . self::SIZE),
            new HttpResponse(201, 'text/plain', '0-131071/' . self::SIZE),
            new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
        ])->setFragmentLength(1)->setRetryAttempts(1);
        $uploader->setSmartResume(false);

        self::assertSame('upload-token', $uploader->uploadAudio($this->createContent()));
        self::assertSame(2, $uploader->getLastUploadAttempts());
        self::assertCount(1, $this->apiHttpClient->posts);
        self::assertSame(
            [
                UploadEventType::UrlRequest,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
                UploadEventType::Error,
                UploadEventType::Reset,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
            ],
            $this->events,
        );
        self::assertSame(
            [
                'bytes 0-65535/150000',
                'bytes 65536-131071/150000',
                'bytes 0-65535/150000',
                'bytes 65536-131071/150000',
                'bytes 131072-149999/150000',
            ],
            $this->contentRanges(),
        );
    }

    public function testSmartResumeDisabledSkipsStatusForProvidedEndpoint(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
        ]);
        $uploader->setSmartResume(false);
        $uploader->setUploadEndpoint(UploadEndpoint::newFromData([
            'url' => 'https://upload.test/old',
            'token' => 'old',
        ]), false);

        self::assertSame('old', $uploader->uploadVideo($this->createContent()));
        self::assertCount(1, $this->httpClient->requests);
        self::assertSame('POST', $this->httpClient->requests[0]->getMethod());
        self::assertSame([UploadEventType::SendFragment], $this->events);
    }

    public function testTimeoutAfterPartialNonSeekableSendIsNotRetried(): void
    {
        $uploader = $this->createUploader([FakeHttpClient::timeout()]);

        $this->expectException(TimeoutException::class);
        $uploader->uploadVideo(new FakeContent('video', false));
    }

    public function testUnexpected200IsNotRetriedForNonSeekableContent(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(200, 'text/plain', '0-65535/' . self::SIZE),
        ])->setFragmentLength(1)->setRetryAttempts(3);

        $this->expectException(OkException::class);
        try {
            $uploader->uploadVideo(new FakeContent(str_repeat('x', self::SIZE), false));
        } finally {
            self::assertCount(1, $this->apiHttpClient->posts);
            self::assertCount(1, $this->httpClient->requests);
        }
    }

    public function testUnexpectedResponseOnLaterNonSeekableFragmentIsNotRetried(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", ' . self::SIZE . '));'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        try {
            $content = new StreamContent($pipes[1], 'a.bin', self::SIZE);
            self::assertFalse($content->isSeekable());
            $uploader = $this->createUploader([
                new HttpResponse(201, 'text/plain', '0-65535/' . self::SIZE),
                // Сервер отвечает, не прочитав тело второго фрагмента.
                new HttpResponse(200, 'text/plain', '0-131071/' . self::SIZE),
            ])->setFragmentLength(1)->setRetryAttempts(3);
            $this->httpClient->unreadBodies = [1];

            try {
                $uploader->uploadVideo($content);
                self::fail('Upload must fail.');
            } catch (OkException) {
                // Наружу выходит исходная ошибка сервера, а не ошибка перемотки потока.
            }

            self::assertSame(['bytes 0-65535/150000', 'bytes 65536-131071/150000'], $this->contentRanges());
            self::assertCount(1, $this->apiHttpClient->posts);
            self::assertSame([
                UploadEventType::UrlRequest,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
                UploadEventType::Error,
            ], $this->events);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function testUnexpectedUploadRangeThrowsException(): void
    {
        $uploader = $this->createUploader([new HttpResponse(200, 'text/plain', '0-1/' . self::SIZE)]);

        $this->expectException(UnexpectedUploadRangeException::class);
        $uploader->uploadVideo($this->createContent());
    }

    public function testUploadFileExInFragments(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(201),
            new HttpResponse(201),
            new HttpResponse(200, self::JSON, '{"fileId":3,"token":"file-token"}'),
        ])->setFragmentLength(1);

        $file = $uploader->uploadFileEx($this->createContent());

        self::assertSame(3, $file->getFileId());
        self::assertSame('file-token', $file->getToken());
        self::assertSame(['type' => 'file'], $this->apiHttpClient->posts[0]['query']);
        self::assertSame(
            ['bytes 0-65535/150000', 'bytes 65536-131071/150000', 'bytes 131072-149999/150000'],
            $this->contentRanges(),
        );
        self::assertSame(self::JSON, $this->httpClient->requests[0]->getHeaders()['Accept']);
    }

    public function testUploadImage(): void
    {
        $uploader = $this->createUploader([
            new HttpResponse(200, self::JSON, '{"photos":{"a":{"token":"image-token"}}}'),
        ]);

        self::assertSame('image-token', $uploader->uploadImage(new StringContent('image', 'a.png')));
    }

    public function testUploadRangeWithDifferentTotalThrowsException(): void
    {
        $uploader = $this->createUploader([new HttpResponse(200, 'text/plain', '0-149999/2')]);

        $this->expectException(UnexpectedUploadRangeException::class);
        $uploader->uploadVideo($this->createContent());
    }

    public function testUploadVideoInFragments(): void
    {
        $progress = [];
        $uploader = $this
            ->createUploader([
                new HttpResponse(201, 'text/plain', '0-65535/' . self::SIZE),
                new HttpResponse(201, 'text/plain', '0-131071/' . self::SIZE),
                new HttpResponse(200, 'text/plain', '0-149999/' . self::SIZE),
            ])
            ->setFragmentLength(1)
            ->setProgressCallback(static function (Progress $p) use (&$progress): void {
                $progress[] = $p;
            });

        $token = $uploader->uploadVideo($this->createContent());

        self::assertSame('upload-token', $token);
        self::assertSame([['path' => '/uploads', 'query' => ['type' => 'video']]], $this->apiHttpClient->posts);
        self::assertSame(
            [
                UploadEventType::UrlRequest,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
                UploadEventType::SendFragment,
            ],
            $this->events,
        );
        self::assertSame(
            ['bytes 0-65535/150000', 'bytes 65536-131071/150000', 'bytes 131072-149999/150000'],
            $this->contentRanges(),
        );
        self::assertSame(
            [self::MIN_FRAGMENT, self::MIN_FRAGMENT, self::SIZE - 2 * self::MIN_FRAGMENT],
            array_map(strlen(...), $this->httpClient->bodies),
        );

        $last = end($progress);
        self::assertInstanceOf(Progress::class, $last);
        self::assertSame(2 * self::MIN_FRAGMENT, $last->fragmentOffset);
        self::assertSame(self::SIZE - 2 * self::MIN_FRAGMENT, $last->fragmentLength);
        self::assertSame(self::SIZE - 2 * self::MIN_FRAGMENT, $last->bytesSent);
        self::assertSame(self::SIZE, $last->size);
    }

    /**
     * @return list<string>
     */
    private function contentRanges(): array
    {
        $ranges = [];
        foreach ($this->httpClient->requests as $request) {
            if ($request->getMethod() === 'POST') {
                $ranges[] = $request->getHeaders()['Content-Range'] ?? '';
            }
        }

        return $ranges;
    }

    private function createContent(): StringContent
    {
        return new StringContent(str_repeat('x', self::SIZE), 'a.mp4');
    }

    /**
     * @param list<HttpResponse|TimeoutException> $responses
     * @param list<array> $endpoints
     */
    private function createUploader(array $responses, array $endpoints = [self::ENDPOINT]): MaxUploader
    {
        $this->apiHttpClient = new FakeMaxHttpClient($endpoints);
        $this->httpClient = new FakeHttpClient($responses);
        $this->events = [];

        $uploader = new MaxUploader(
            new MaxApiClient('test-token', httpClient: $this->apiHttpClient),
            new MaxUploaderHttpClient($this->httpClient),
        );

        return $uploader->setEventCallback(function (UploadEventType $event): void {
            $this->events[] = $event;
        });
    }
}
