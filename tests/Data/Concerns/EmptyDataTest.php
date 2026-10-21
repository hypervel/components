<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Concerns\EmptyDataTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Resource;
use Hypervel\Testbench\TestCase;

class EmptyDataTest extends TestCase
{
    /**
     * Get package providers for the empty data test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Test a custom empty value replaces null without replacing supplied values.
     */
    public function testReplacesNullValues(): void
    {
        $this->assertSame([
            'value' => 'supplied',
        ], SimpleEmptyData::empty(['value' => 'supplied'], '?'));

        $this->assertSame([
            'value' => '?',
        ], SimpleEmptyData::empty(replaceNullValuesWith: '?'));
    }

    /**
     * Test constructor object defaults are fresh for each empty call.
     */
    public function testDoesNotRetainDefaultObjectsInMetadata(): void
    {
        $first = DefaultObjectData::empty()['value'];
        $second = DefaultObjectData::empty()['value'];

        $this->assertInstanceOf(EmptyDefaultObject::class, $first);
        $this->assertInstanceOf(EmptyDefaultObject::class, $second);
        $this->assertNotSame($first, $second);
    }

    /**
     * Test resources expose the empty representation capability.
     */
    public function testResourceCreatesEmptyRepresentation(): void
    {
        $this->assertSame(['value' => null], EmptyResource::empty());
    }
}

class SimpleEmptyData extends Data
{
    public string $value;
}

class DefaultObjectData extends Data
{
    public function __construct(public EmptyDefaultObject $value = new EmptyDefaultObject)
    {
    }
}

class EmptyDefaultObject
{
}

class EmptyResource extends Resource
{
    public string $value;
}
