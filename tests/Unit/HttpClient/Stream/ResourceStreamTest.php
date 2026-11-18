<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit\HttpClient\Stream;

use Codeception\Test\Unit;
use MaxMessenger\Uploader\Exception\StreamException;
use MaxMessenger\Uploader\HttpClient\Stream\ResourceStream;

use function fclose;
use function fopen;
use function fseek;
use function fwrite;
use function is_resource;

final class ResourceStreamTest extends Unit
{
    /**
     * @var resource|closed-resource|null
     */
    private $resource = null;

    public function testNonSeekableAtOffsetReads(): void
    {
        $resource = $this->createResource('0123456789');
        fseek($resource, 3);

        $stream = new ResourceStream($resource, false, 3, 4);

        self::assertFalse($stream->isSeekable());
        self::assertSame('3456', $stream->getContents());
    }

    public function testNonSeekableSeekThrowsException(): void
    {
        $resource = $this->createResource('0123456789');
        $stream = new ResourceStream($resource, false, 0, 10);
        $stream->read(5);

        $this->expectException(StreamException::class);
        $stream->seek(0);
    }

    public function testSeekableReadsFragment(): void
    {
        $stream = new ResourceStream($this->createResource('0123456789'), true, 2, 5);

        self::assertTrue($stream->isSeekable());
        self::assertSame(5, $stream->getSize());
        self::assertSame('234', $stream->read(3));

        $stream->seek(1);
        self::assertSame('3456', $stream->getContents());
    }

    protected function _after(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    /**
     * @return resource
     */
    private function createResource(string $content)
    {
        $resource = fopen('php://memory', 'w+b');
        self::assertNotFalse($resource);
        fwrite($resource, $content);
        fseek($resource, 0);

        return $this->resource = $resource;
    }
}
