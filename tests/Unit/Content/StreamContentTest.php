<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit\Content;

use Codeception\Test\Unit;
use MaxMessenger\Uploader\Content\StreamContent;
use MaxMessenger\Uploader\Exception\StreamException;
use MaxMessenger\Uploader\Exception\StreamLogicException;
use MaxMessenger\Uploader\Tests\Support\Fake\NoStatStreamWrapper;

use function fclose;
use function fopen;
use function fstat;
use function fwrite;
use function is_resource;
use function proc_close;
use function proc_open;
use function sprintf;
use function str_repeat;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_wrapper_register;
use function stream_wrapper_unregister;

final class StreamContentTest extends Unit
{
    /**
     * @var resource|closed-resource|null
     */
    private $resource = null;

    public function testAcceptsMaximumPostNameLength(): void
    {
        $postName = str_repeat('я', 196) . '.txt';
        $content = new StreamContent($this->createResource('data'), str_repeat('dir/', 100) . $postName);

        self::assertSame($postName, $content->postName);
        self::assertSame($postName, $content->getPostName());
    }

    public function testAcceptsZeroPostName(): void
    {
        $content = new StreamContent($this->createResource('data'), '0');

        self::assertSame('0', $content->postName);
        self::assertSame('0', $content->getPostName());
    }

    public function testEmptyPostNameThrowsException(): void
    {
        $resource = $this->createResource('data');

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Failed to extract post name.');
        new StreamContent($resource, '');
    }

    public function testEmptyStreamThrowsException(): void
    {
        $resource = $this->createResource('');

        $this->expectException(StreamException::class);
        new StreamContent($resource, 'a.bin');
    }

    public function testLongPostNameIsPreserved(): void
    {
        $resource = $this->createResource('data');
        $postName = str_repeat('я', 197) . '.txt';
        $content = new StreamContent($resource, $postName);

        self::assertSame($postName, $content->getPostName());
    }

    public function testMemoryStreamFragmentsReconstructContentExactly(): void
    {
        $source = '';
        for ($i = 0; $i < 250; ++$i) {
            $source .= sprintf('%04d', $i);
        }
        $content = new StreamContent($this->createResource($source), 'a.bin');

        $part1 = $content->getStream(0, 333)->getContents();
        $part2 = $content->getStream(333, 334)->getContents();
        $part3 = $content->getStream(667, 333)->getContents();

        self::assertSame($source, $part1 . $part2 . $part3);
    }

    public function testMissingBasenameThrowsException(): void
    {
        $resource = $this->createResource('data');

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Failed to extract post name.');
        new StreamContent($resource, '/');
    }

    public function testNonSeekablePipeContinuesAtNextFragment(): void
    {
        $source = '';
        for ($i = 0; $i < 250; ++$i) {
            $source .= sprintf('%04d', $i);
        }
        $process = proc_open(
            [PHP_BINARY, '-r', 'for ($i = 0; $i < 250; ++$i) { fwrite(STDOUT, sprintf("%04d", $i)); }'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        try {
            self::assertFalse(stream_get_meta_data($pipes[1])['seekable']);
            $content = new StreamContent($pipes[1], 'a.bin', 1000);
            $first = $content->getStream(0, 333);
            $part1 = $first->read(100);
            try {
                $content->getStream(333, 334);
                self::fail('Unread bytes were skipped.');
            } catch (StreamException $e) {
                self::assertSame('Non-seekable stream is not at the requested offset.', $e->getMessage());
            }
            $part1 .= $first->getContents();

            $second = $content->getStream(333, 334);
            self::assertNotSame($first, $second);
            $part2 = $second->getContents();

            $third = $content->getStream(667, 333);
            self::assertNotSame($second, $third);
            self::assertSame($source, $part1 . $part2 . $third->getContents());
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function testNonSeekableStreamWithoutSizeThrowsException(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "data");'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        try {
            $this->expectException(StreamLogicException::class);
            $this->expectExceptionMessage('A non-seekable stream requires an explicit $size.');
            new StreamContent($pipes[1], 'a.bin');
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function testNotResourceThrowsException(): void
    {
        $this->expectException(StreamLogicException::class);
        $this->expectExceptionMessage('A stream resource is expected.');
        /** @psalm-suppress InvalidArgument */
        new StreamContent('not a resource', 'a.bin');
    }

    public function testNotStreamResourceThrowsException(): void
    {
        $this->expectException(StreamLogicException::class);
        $this->expectExceptionMessage('A stream resource is expected.');
        new StreamContent(stream_context_create(), 'a.bin');
    }

    public function testProvidesStreamContent(): void
    {
        $content = new StreamContent($this->createResource('0123456789'), 'dir/a.bin');
        $first = $content->getStream(4, 3);

        self::assertSame('application/octet-stream', $content->getContentType());
        self::assertSame('a.bin', $content->getPostName());
        self::assertSame('a.bin', $content->postName);
        self::assertSame(10, $content->getSize());
        self::assertSame('456', $first->getContents());
        $second = $content->getStream(0, 4);
        self::assertNotSame($first, $second);
        self::assertSame('0123', $second->getContents());
    }

    public function testSeekableStreamWithoutStatUsesSeekToDetermineSize(): void
    {
        self::assertTrue(stream_wrapper_register('maxuploadertest', NoStatStreamWrapper::class));
        try {
            $resource = fopen('maxuploadertest://data', 'rb');
            self::assertNotFalse($resource);
            $this->resource = $resource;
            self::assertTrue(stream_get_meta_data($resource)['seekable']);
            self::assertFalse(@fstat($resource));

            $content = new StreamContent($resource, 'a.bin');

            self::assertSame(10, $content->getSize());
            self::assertSame('3456', $content->getStream(3, 4)->getContents());
        } finally {
            self::assertTrue(stream_wrapper_unregister('maxuploadertest'));
        }
    }

    public function testUsesGivenSize(): void
    {
        $content = new StreamContent($this->createResource('0123456789'), 'a.bin', 5);

        self::assertSame(5, $content->getSize());
    }

    public function testWriteOnlyStreamThrowsException(): void
    {
        $resource = fopen('php://output', 'wb');
        self::assertNotFalse($resource);
        $this->resource = $resource;

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Stream must be readable.');
        new StreamContent($resource, 'a.bin', 10);
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

        return $this->resource = $resource;
    }
}
