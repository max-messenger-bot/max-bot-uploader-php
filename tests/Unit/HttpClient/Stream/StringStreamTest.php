<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit\HttpClient\Stream;

use Codeception\Test\Unit;
use MaxMessenger\Uploader\Exception\StreamLogicException;
use MaxMessenger\Uploader\HttpClient\Stream\StringStream;

use const SEEK_CUR;
use const SEEK_END;

final class StringStreamTest extends Unit
{
    public function testGetContentsReadsFragment(): void
    {
        $stream = new StringStream('0123456789', 2, 5);

        self::assertSame(5, $stream->getSize());
        self::assertTrue($stream->isSeekable());
        self::assertSame('23456', $stream->getContents());
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(10));
    }

    public function testNegativeOffsetThrowsException(): void
    {
        $this->expectException(StreamLogicException::class);
        /** @psalm-suppress InvalidArgument */
        new StringStream('abc', -1, 1);
    }

    public function testNotEnoughDataThrowsException(): void
    {
        $this->expectException(StreamLogicException::class);
        new StringStream('abc', 0, 5);
    }

    public function testOffsetPastEndThrowsException(): void
    {
        $this->expectException(StreamLogicException::class);
        new StringStream('abc', PHP_INT_MAX, 1);
    }

    public function testPositionOutsideStreamThrowsException(): void
    {
        $stream = new StringStream('abc', 0, 3);

        $this->expectException(StreamLogicException::class);
        $stream->seek(4);
    }

    public function testProgressCallbackReceivesCursorAndLength(): void
    {
        $calls = [];
        $stream = new StringStream('0123456789', 0, 10, static function (int $sent, int $total) use (&$calls): void {
            $calls[] = [$sent, $total];
        });

        $stream->read(4);
        $stream->read(100);

        self::assertSame([[4, 10], [10, 10]], $calls);
    }

    public function testReadInPortions(): void
    {
        $stream = new StringStream('0123456789', 1, 8);

        self::assertSame('123', $stream->read(3));
        self::assertSame(3, $stream->tell());
        self::assertFalse($stream->eof());
        self::assertSame('45678', $stream->read(100));
        self::assertTrue($stream->eof());
    }

    public function testSeek(): void
    {
        $stream = new StringStream('0123456789', 2, 6);

        $stream->seek(3);
        self::assertSame(3, $stream->tell());
        self::assertSame('56', $stream->read(2));

        $stream->seek(-4, SEEK_CUR);
        self::assertSame('34', $stream->read(2));

        $stream->seek(-1, SEEK_END);
        self::assertSame('7', $stream->getContents());
    }

    public function testUnknownSeekOriginThrowsException(): void
    {
        $stream = new StringStream('abc', 0, 3);

        $this->expectException(StreamLogicException::class);
        $stream->seek(0, 100);
    }

    public function testZeroLengthThrowsException(): void
    {
        $this->expectException(StreamLogicException::class);
        /** @psalm-suppress InvalidArgument */
        new StringStream('abc', 0, 0);
    }
}
