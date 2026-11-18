<?php

declare(strict_types=1);

namespace MaxMessenger\Uploader\Tests\Unit\HttpClient\Body;

use Codeception\Test\Unit;
use MaxMessenger\Uploader\HttpClient\Body\StreamBody;
use MaxMessenger\Uploader\HttpClient\Stream\StringStream;

final class StreamBodyTest extends Unit
{
    public function testReturnsStreamAndContentType(): void
    {
        $stream = new StringStream('abc', 0, 3);
        $body = new StreamBody($stream, 'text/plain');

        self::assertSame($stream, $body->getBody());
        self::assertSame('text/plain', $body->getContentType());
    }
}
