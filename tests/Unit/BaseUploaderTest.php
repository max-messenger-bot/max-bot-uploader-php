<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit;

use Codeception\Test\Unit;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Uploader\Content\StringContent;
use MaxMessenger\Uploader\Contract\ContentInterface;
use MaxMessenger\Uploader\Contract\MaxUploaderHttpClientInterface;
use MaxMessenger\Uploader\Exception\StreamException;
use MaxMessenger\Uploader\MaxMultipartFormUploader;
use MaxMessenger\Uploader\MaxUploader;
use MaxMessenger\Uploader\Tests\Support\Fake\FakeMaxHttpClient;

use function chr;
use function str_repeat;
use function str_split;

/**
 * Проверяет общие ограничения загрузчиков.
 */
final class BaseUploaderTest extends Unit
{
    /**
     * Проверяет загрузку имени длиной 200 символов, включая расширение.
     */
    public function testAcceptsFileNameOf200Characters(): void
    {
        foreach ([MaxMultipartFormUploader::class, MaxUploader::class] as $class) {
            foreach (['a', 'я'] as $character) {
                $apiClient = new FakeMaxHttpClient([['url' => 'https://upload.test/file']]);
                $uploadClient = $this->createMock(MaxUploaderHttpClientInterface::class);
                $uploadClient->method('postMultipartForm')->willReturn(['fileId' => 7, 'token' => 'file-token']);
                $uploadClient->method('postResumableUpload')->willReturn(['fileId' => 7, 'token' => 'file-token']);
                $uploader = new $class(new MaxApiClient('test-token', httpClient: $apiClient), $uploadClient);
                $content = new StringContent('file', 'directory/' . str_repeat($character, 196) . '.txt');

                self::assertSame('file-token', $uploader->uploadFile($content));
                self::assertCount(1, $apiClient->posts);
            }
        }
    }

    public function testRejectsEmptyFileName(): void
    {
        foreach ([MaxMultipartFormUploader::class, MaxUploader::class] as $class) {
            $apiClient = new FakeMaxHttpClient();
            $uploadClient = $this->createMock(MaxUploaderHttpClientInterface::class);
            $uploadClient->expects(self::never())->method('postMultipartForm');
            $uploadClient->expects(self::never())->method('postResumableUpload');
            $uploader = new $class(new MaxApiClient('test-token', httpClient: $apiClient), $uploadClient);
            $content = $this->createMock(ContentInterface::class);
            $content->method('getSize')->willReturn(4);
            $content->method('getPostName')->willReturn('');

            try {
                $uploader->uploadFile($content);
                self::fail('An empty file name was accepted.');
            } catch (StreamException $e) {
                self::assertSame('Failed to extract post name.', $e->getMessage());
                self::assertSame([], $apiClient->posts);
                self::assertSame(0, $uploader->getLastUploadAttempts());
            }
        }
    }

    /**
     * Проверяет отказ до сетевых запросов для всех методов загрузки при имени длиннее 200 символов.
     */
    public function testRejectsFileNameOver200Characters(): void
    {
        foreach ([MaxMultipartFormUploader::class, MaxUploader::class] as $class) {
            foreach (['a', 'я'] as $character) {
                foreach ([
                    'uploadAudio',
                    'uploadFile',
                    'uploadFileEx',
                    'uploadImage',
                    'uploadImageEx',
                    'uploadVideo',
                ] as $method) {
                    $apiClient = new FakeMaxHttpClient();
                    $uploadClient = $this->createMock(MaxUploaderHttpClientInterface::class);
                    $uploadClient->expects(self::never())->method('postMultipartForm');
                    $uploadClient->expects(self::never())->method('postResumableUpload');
                    $uploadClient->expects(self::never())->method('getResumableUploadInfo');
                    $uploader = new $class(new MaxApiClient('test-token', httpClient: $apiClient), $uploadClient);
                    $content = $this->createMock(ContentInterface::class);
                    $content->method('getSize')->willReturn(4);
                    $content->method('getPostName')->willReturn(str_repeat($character, 197) . '.txt');

                    try {
                        $uploader->$method($content);
                        self::fail('Expected file name validation exception.');
                    } catch (StreamException $e) {
                        self::assertSame('File name must not exceed 200 characters.', $e->getMessage());
                        self::assertSame([], $apiClient->posts);
                        self::assertSame(0, $uploader->getLastUploadAttempts());
                    }
                }
            }
        }
    }

    public function testRejectsForbiddenFileNameCharacters(): void
    {
        $characters = str_split('\\/:*"<>|');
        for ($code = 0; $code < 32; ++$code) {
            $characters[] = chr($code);
        }

        foreach ([MaxMultipartFormUploader::class, MaxUploader::class] as $class) {
            foreach ($characters as $character) {
                $apiClient = new FakeMaxHttpClient();
                $uploadClient = $this->createMock(MaxUploaderHttpClientInterface::class);
                $uploadClient->expects(self::never())->method('postMultipartForm');
                $uploadClient->expects(self::never())->method('postResumableUpload');
                $uploader = new $class(new MaxApiClient('test-token', httpClient: $apiClient), $uploadClient);
                $content = $this->createMock(ContentInterface::class);
                $content->method('getSize')->willReturn(4);
                $content->method('getPostName')->willReturn('file' . $character . '.txt');

                try {
                    $uploader->uploadFile($content);
                    self::fail('A forbidden character was accepted.');
                } catch (StreamException $e) {
                    self::assertSame('File name contains forbidden characters.', $e->getMessage());
                    self::assertSame([], $apiClient->posts);
                    self::assertSame(0, $uploader->getLastUploadAttempts());
                }
            }
        }
    }
}
