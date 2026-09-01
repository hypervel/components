<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Support;

use Hypervel\OpenTelemetry\Support\RedisQueryTextFormatter;
use Hypervel\Tests\TestCase;
use RuntimeException;

class RedisQueryTextFormatterTest extends TestCase
{
    public function testFormatsOnlyClassifiedKeyAndFieldPositions(): void
    {
        $formatter = new RedisQueryTextFormatter;

        $this->assertSame('GET customer:1', $formatter->format('GET', ['customer:1']));
        $this->assertSame('DEL first 2 third', $formatter->format('DEL', ['first', 2, 'third']));
        $this->assertSame('DEL 1 0', $formatter->format('DEL', [true, false]));
        $this->assertSame('RENAME old new', $formatter->format('RENAME', ['old', 'new']));
        $this->assertSame(
            'HSET customer:1 email status',
            $formatter->format('HSET', ['customer:1', 'email', 'secret', 'status', 'active']),
        );
        $this->assertSame(
            'HDEL customer:1 email status',
            $formatter->format('HDEL', ['customer:1', 'email', 'status']),
        );
        $this->assertSame(
            'BITOP destination source:1 source:2',
            $formatter->format('BITOP', ['AND', 'destination', 'source:1', 'source:2']),
        );
    }

    public function testExcludesValuesCredentialsScriptsMessagesAndUnclassifiedArguments(): void
    {
        $formatter = new RedisQueryTextFormatter;

        $this->assertSame('SET customer:1', $formatter->format('SET', ['customer:1', 'private-value']));
        $this->assertSame('PUBLISH orders', $formatter->format('PUBLISH', ['orders', 'private-message']));
        $this->assertSame('AUTH', $formatter->format('AUTH', ['username', 'password']));
        $this->assertSame('ECHO', $formatter->format('ECHO', ['private-message']));
        $this->assertSame('EVAL', $formatter->format('EVAL', ['return ARGV[1]', 0, 'secret']));
        $this->assertSame('FCALL', $formatter->format('FCALL', ['function-name', 0, 'secret']));
        $this->assertSame('MSET', $formatter->format('MSET', [['key' => 'value']]));
        $this->assertSame('JSON.SET', $formatter->format('JSON.SET', ['key', '$', 'private-value']));
    }

    public function testNeverWalksArraysOrStringifiesObjects(): void
    {
        $formatter = new RedisQueryTextFormatter;

        $this->assertSame(
            'DEL visible',
            $formatter->format('DEL', [
                ['nested-secret'],
                new RedisQueryTextObjectProbe,
                static fn (): string => 'callable-secret',
                'visible',
            ]),
        );
        $this->assertSame('HMGET hash', $formatter->format('HMGET', ['hash', ['field', 'secret']]));
    }
}

class RedisQueryTextObjectProbe
{
    public function __toString(): string
    {
        throw new RuntimeException('Redis query text must not stringify objects.');
    }
}
