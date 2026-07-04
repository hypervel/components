<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\RetrievesMultipleKeys;
use Hypervel\Tests\TestCase;

class CacheRetrievesMultipleKeysTest extends TestCase
{
    public function testPutManyReturnsTrueForEmptyInput(): void
    {
        $store = new RetrievesMultipleKeysPutManyProbe;

        $this->assertTrue($store->putMany([], 60));
        $this->assertSame([], $store->calls);
    }

    public function testPutManyReturnsFalseWhenAnyWriteFailsAndAttemptsEveryValue(): void
    {
        $store = new RetrievesMultipleKeysPutManyProbe(['fail']);

        $this->assertFalse($store->putMany([
            'first' => 'one',
            'fail' => 'two',
            'after' => 'three',
        ], 60));
        $this->assertSame(['first', 'fail', 'after'], $store->calls);
    }

    public function testPutManyReturnsTrueWhenEveryWriteSucceeds(): void
    {
        $store = new RetrievesMultipleKeysPutManyProbe;

        $this->assertTrue($store->putMany([
            'first' => 'one',
            'second' => 'two',
        ], 60));
        $this->assertSame(['first', 'second'], $store->calls);
    }
}

class RetrievesMultipleKeysPutManyProbe
{
    use RetrievesMultipleKeys;

    /**
     * @var list<string>
     */
    public array $calls = [];

    public function __construct(private array $failures = [])
    {
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        $this->calls[] = $key;

        return ! in_array($key, $this->failures, true);
    }
}
