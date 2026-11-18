<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit\Content;

use Codeception\Test\Unit;
use MaxMessenger\Uploader\Content\StringContent;
use MaxMessenger\Uploader\Exception\StreamException;

use function str_repeat;

final class StringContentTest extends Unit
{
    public function testAcceptsMaximumPostNameLength(): void
    {
        $postName = str_repeat('я', 196) . '.txt';
        $content = new StringContent('data', str_repeat('dir/', 100) . $postName);

        self::assertSame($postName, $content->postName);
        self::assertSame($postName, $content->getPostName());
    }

    public function testAcceptsZeroPostName(): void
    {
        $content = new StringContent('data', '0');

        self::assertSame('0', $content->postName);
        self::assertSame('0', $content->getPostName());
    }

    public function testEmptyContentThrowsException(): void
    {
        $this->expectException(StreamException::class);
        /** @psalm-suppress InvalidArgument */
        new StringContent('', 'a.txt');
    }

    public function testEmptyPostNameThrowsException(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Failed to extract post name.');
        new StringContent('data', '');
    }

    public function testKeepsQuestionMarkInLocalPostName(): void
    {
        $content = new StringContent('data', 'dir/what?.jpg');

        self::assertSame('what?.jpg', $content->postName);
        self::assertSame('what?.jpg', $content->getPostName());
    }

    public function testLongPostNameIsPreserved(): void
    {
        $postName = str_repeat('я', 197) . '.txt';
        $content = new StringContent('data', $postName);

        self::assertSame($postName, $content->getPostName());
    }

    public function testMissingBasenameThrowsException(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Failed to extract post name.');
        new StringContent('data', '/');
    }

    public function testProvidesContent(): void
    {
        $content = new StringContent('0123456789', 'dir/a.txt');

        self::assertSame('application/octet-stream', $content->getContentType());
        self::assertSame('a.txt', $content->getPostName());
        self::assertSame('a.txt', $content->postName);
        self::assertSame(10, $content->getSize());
        self::assertSame('3456', $content->getStream(3, 4)->getContents());
    }

    public function testRemovesQueryFromUrl(): void
    {
        $content = new StringContent(
            'data',
            'https://example.test/dir/ignored/file.txt?token="<>|/' . str_repeat('x', 250),
        );

        self::assertSame('file.txt', $content->postName);
        self::assertSame('file.txt', $content->getPostName());
    }

    public function testRemovesQueryWithNestedUrlFromUrl(): void
    {
        $content = new StringContent('data', 'ftp://example.test/a.mp4?redirect=https://example.test/b.mp4');

        self::assertSame('a.mp4', $content->getPostName());
    }

    public function testStreamCallsProgressCallback(): void
    {
        $calls = [];
        $stream = (new StringContent('0123456789', 'a.txt'))
            ->getStream(0, 10, static function (int $sent, int $total) use (&$calls): void {
                $calls[] = [$sent, $total];
            });

        $stream->getContents();

        self::assertSame([[10, 10]], $calls);
    }
}
