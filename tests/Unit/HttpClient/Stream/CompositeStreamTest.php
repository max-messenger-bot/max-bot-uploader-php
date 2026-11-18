<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit\HttpClient\Stream;

use Codeception\Test\Unit;
use MaxMessenger\Uploader\HttpClient\Stream\CompositeStream;
use MaxMessenger\Uploader\HttpClient\Stream\StringStream;
use MaxMessenger\Uploader\Tests\Support\Fake\FakeStream;

final class CompositeStreamTest extends Unit
{
    public function testNotSeekableIfAnyStreamIsNotSeekable(): void
    {
        $stream = new CompositeStream([
            new StringStream('abc', 0, 3),
            new FakeStream('def', false),
        ]);

        self::assertFalse($stream->isSeekable());
        self::assertSame('abcdef', $stream->getContents());
    }

    public function testProgressCallbackReceivesTotalLength(): void
    {
        $calls = [];
        $stream = new CompositeStream(
            [new StringStream('abc', 0, 3), new StringStream('de', 0, 2)],
            static function (int $sent, int $total) use (&$calls): void {
                $calls[] = [$sent, $total];
            },
        );

        $stream->read(4);
        $stream->read(4);

        self::assertSame([[4, 5], [5, 5]], $calls);
    }

    public function testReadsStreamsInOrder(): void
    {
        $stream = new CompositeStream([
            new StringStream('abc', 0, 3),
            new StringStream('de', 0, 2),
            new StringStream('fghi', 0, 4),
        ]);

        self::assertSame(9, $stream->getSize());
        self::assertTrue($stream->isSeekable());
        self::assertSame('abcd', $stream->read(4));
        self::assertSame('efghi', $stream->read(100));
        self::assertTrue($stream->eof());
    }

    public function testSeekAcrossStreams(): void
    {
        $stream = new CompositeStream([
            new StringStream('abc', 0, 3),
            new StringStream('de', 0, 2),
            new StringStream('fghi', 0, 4),
        ]);
        $stream->getContents();

        $stream->seek(4);
        self::assertSame('efg', $stream->read(3));

        $stream->seek(1);
        self::assertSame('bcdefghi', $stream->getContents());

        $stream->seek(5);
        self::assertSame('fghi', $stream->getContents());
    }
}
