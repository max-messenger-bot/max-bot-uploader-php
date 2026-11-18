<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit\Content;

use Codeception\Test\Unit;
use MaxMessenger\Uploader\Content\FileContent;
use MaxMessenger\Uploader\Exception\StreamException;

use function basename;
use function file_put_contents;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class FileContentTest extends Unit
{
    private ?string $fileName = null;

    public function testCustomPostNameAndSize(): void
    {
        $content = new FileContent($this->createFile('0123456789'), 'other/name.bin', 4);

        self::assertSame('name.bin', $content->getPostName());
        self::assertSame('name.bin', $content->postName);
        self::assertSame(4, $content->getSize());
    }

    public function testEmptyFileThrowsException(): void
    {
        $fileName = $this->createFile('');

        $this->expectException(StreamException::class);
        new FileContent($fileName);
    }

    public function testFragmentsReconstructFileExactly(): void
    {
        $source = '';
        for ($i = 0; $i < 250; ++$i) {
            $source .= sprintf('%04d', $i);
        }
        $content = new FileContent($this->createFile($source));

        $part1 = $content->getStream(0, 333)->getContents();
        $part2 = $content->getStream(333, 334)->getContents();
        $part3 = $content->getStream(667, 333)->getContents();

        self::assertSame($source, $part1 . $part2 . $part3);
    }

    public function testMissingFileThrowsException(): void
    {
        $this->expectException(StreamException::class);
        new FileContent(sys_get_temp_dir() . '/max-uploader-missing-file');
    }

    public function testProvidesFileContent(): void
    {
        $fileName = $this->createFile('0123456789');
        $content = new FileContent($fileName);

        self::assertSame('application/octet-stream', $content->getContentType());
        self::assertSame(basename($fileName), $content->getPostName());
        self::assertSame(10, $content->getSize());
        self::assertSame('2345', $content->getStream(2, 4)->getContents());
        self::assertSame('89', $content->getStream(8, 2)->getContents());
    }

    protected function _after(): void
    {
        if ($this->fileName !== null) {
            @unlink($this->fileName);
            $this->fileName = null;
        }
    }

    /**
     * @return non-empty-string
     */
    private function createFile(string $content): string
    {
        $fileName = tempnam(sys_get_temp_dir(), 'max-uploader-');
        if ($fileName === false || $fileName === '') {
            self::fail('Failed to create a temporary file.');
        }
        file_put_contents($fileName, $content);

        return $this->fileName = $fileName;
    }
}
