<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit\HttpClient\Stream;

use Codeception\Test\Unit;
use MaxMessenger\Uploader\HttpClient\Stream\MultipartFormStream;
use MaxMessenger\Uploader\HttpClient\Stream\StringStream;

use function preg_match;
use function strlen;

final class MultipartFormStreamTest extends Unit
{
    public function testBuildsForm(): void
    {
        $stream = new MultipartFormStream(
            new StringStream('содержимое', 0, strlen('содержимое')),
            'file.txt',
            'text/plain',
        );

        self::assertSame(
            1,
            preg_match('/^multipart\/form-data; boundary=([0-9a-f]{32})$/', $stream->getContentType(), $matches),
        );
        $boundary = $matches[1];

        $expected = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"data\"; filename=\"file.txt\"\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "содержимое\r\n"
            . "--$boundary--\r\n";

        self::assertSame(strlen($expected), $stream->getSize());
        self::assertSame($expected, $stream->getContents());
    }

    public function testEscapesPostName(): void
    {
        $stream = new MultipartFormStream(new StringStream('x', 0, 1), "a\"b\r\nc.txt", 'text/plain');

        self::assertStringContainsString('filename="abc.txt"', $stream->getContents());
    }

    public function testUsesUniqueBoundary(): void
    {
        $first = new MultipartFormStream(new StringStream('x', 0, 1), 'a.txt', 'text/plain');
        $second = new MultipartFormStream(new StringStream('x', 0, 1), 'a.txt', 'text/plain');

        self::assertNotSame($first->getContentType(), $second->getContentType());
    }
}
