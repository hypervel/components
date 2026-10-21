<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Subscriber;

use Hypervel\Redis\Subscriber\CommandBuilder;
use Hypervel\Tests\TestCase;

class CommandBuilderTest extends TestCase
{
    public function testBuildNull(): void
    {
        $this->assertSame("\$-1\r\n", CommandBuilder::build(null));
    }

    public function testBuildInteger(): void
    {
        $this->assertSame(":1\r\n", CommandBuilder::build(1));
    }

    public function testBuildString(): void
    {
        $this->assertSame("\$3\r\nfoo\r\n", CommandBuilder::build('foo'));
    }

    public function testBuildSimpleArray(): void
    {
        $this->assertSame(
            "*2\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n",
            CommandBuilder::build(['foo', 'bar'])
        );
    }

    public function testBuildNestedArray(): void
    {
        $this->assertSame(
            "*4\r\n:1\r\n*2\r\n:2\r\n\$1\r\n4\r\n:2\r\n\$3\r\nbar\r\n",
            CommandBuilder::build([1, [2, '4'], 2, 'bar'])
        );
    }

    public function testBuildPing(): void
    {
        $this->assertSame("PING\r\n", CommandBuilder::build('ping'));
    }

    public function testBuildEmptyString(): void
    {
        $this->assertSame("\$0\r\n\r\n", CommandBuilder::build(''));
    }

    public function testBuildEmptyArray(): void
    {
        $this->assertSame("*0\r\n", CommandBuilder::build([]));
    }

    public function testBuildZeroInteger(): void
    {
        $this->assertSame(":0\r\n", CommandBuilder::build(0));
    }

    public function testBuildNegativeInteger(): void
    {
        $this->assertSame(":-5\r\n", CommandBuilder::build(-5));
    }

    public function testBuildSubscribeCommand(): void
    {
        $this->assertSame(
            "*2\r\n\$9\r\nsubscribe\r\n\$10\r\nmy-channel\r\n",
            CommandBuilder::build(['subscribe', 'my-channel'])
        );
    }

    public function testBuildAuthCommand(): void
    {
        $this->assertSame(
            "*2\r\n\$4\r\nauth\r\n\$8\r\npassword\r\n",
            CommandBuilder::build(['auth', 'password'])
        );
    }
}
