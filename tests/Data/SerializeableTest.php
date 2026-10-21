<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\Lazy\DefaultLazy;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\LazyData;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithPropertyHooks;

class SerializeableTest extends TestCase
{
    // Spatie's snapshots are inline expected strings, so a change to what an object serializes still fails.

    /**
     * Get package providers for the serialization test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanSerializeAndUnserializeADataObject(): void
    {
        $object = SimpleData::from('Hello world');

        $serialized = serialize($object);

        $this->assertSame(
            'O:39:"Hypervel\Tests\Data\Fixtures\SimpleData":4:{s:14:"~*~_additional";a:0:{}s:21:"~*~partialDefinitions";N;s:7:"~*~wrap";N;s:6:"string";s:11:"Hello world";}',
            $this->readable($serialized),
        );

        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(SimpleData::class, $unserialized);
        $this->assertSame('Hello world', $unserialized->string);
    }

    public function testCanSerializeAndUnserializeADataObjectWithAdditionalData(): void
    {
        $object = SimpleData::from('Hello world')->additional([
            'int' => 69,
        ]);

        $serialized = serialize($object);

        $this->assertSame(
            'O:39:"Hypervel\Tests\Data\Fixtures\SimpleData":4:{s:14:"~*~_additional";a:1:{s:3:"int";i:69;}s:21:"~*~partialDefinitions";N;s:7:"~*~wrap";N;s:6:"string";s:11:"Hello world";}',
            $this->readable($serialized),
        );

        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(SimpleData::class, $unserialized);
        $this->assertSame('Hello world', $unserialized->string);
        $this->assertSame(['int' => 69], $unserialized->getAdditionalData());
    }

    public function testCanSerializeAndUnserializeADataObjectWithVirtualProperties(): void
    {
        $object = new SimpleDataWithPropertyHooks;

        $serialized = serialize($object);

        $this->assertSame(
            'O:56:"Hypervel\Tests\Data\Fixtures\SimpleDataWithPropertyHooks":5:{s:14:"~*~_additional";a:0:{}s:21:"~*~partialDefinitions";N;s:7:"~*~wrap";N;s:6:"backed";s:7:"default";s:22:"~*~constructorProperty";s:7:"default";}',
            $this->readable($serialized),
        );

        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(SimpleDataWithPropertyHooks::class, $unserialized);
        $this->assertSame($object->virtual, $unserialized->virtual);
    }

    public function testCanSerializeAndUnserializeADataObjectWithBackedProperties(): void
    {
        $object = new SimpleDataWithPropertyHooks;
        $object->backed = 'Hello world';

        $serialized = serialize($object);

        $this->assertSame(
            'O:56:"Hypervel\Tests\Data\Fixtures\SimpleDataWithPropertyHooks":5:{s:14:"~*~_additional";a:0:{}s:21:"~*~partialDefinitions";N;s:7:"~*~wrap";N;s:6:"backed";s:11:"Hello world";s:22:"~*~constructorProperty";s:7:"default";}',
            $this->readable($serialized),
        );

        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(SimpleDataWithPropertyHooks::class, $unserialized);
        $this->assertSame($object->backed, $unserialized->backed);
    }

    public function testCanSerializeAndUnserializeADataCollection(): void
    {
        $collection = new DataCollection(SimpleData::class, ['A', 'B']);

        $serialized = serialize($collection);

        $this->assertSame(
            'O:28:"Hypervel\Data\DataCollection":4:{s:8:"~*~items";O:27:"Hypervel\Support\Collection":2:{s:8:"~*~items";a:2:{i:0;O:39:"Hypervel\Tests\Data\Fixtures\SimpleData":4:{s:14:"~*~_additional";a:0:{}s:21:"~*~partialDefinitions";N;s:7:"~*~wrap";N;s:6:"string";s:1:"A";}i:1;O:39:"Hypervel\Tests\Data\Fixtures\SimpleData":4:{s:14:"~*~_additional";a:0:{}s:21:"~*~partialDefinitions";N;s:7:"~*~wrap";N;s:6:"string";s:1:"B";}}s:28:"~*~escapeWhenCastingToString";b:0;}s:9:"dataClass";s:39:"Hypervel\Tests\Data\Fixtures\SimpleData";s:21:"~*~partialDefinitions";N;s:7:"~*~wrap";N;}',
            $this->readable($serialized),
        );

        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(DataCollection::class, $unserialized);
        $this->assertEquals(new DataCollection(SimpleData::class, ['A', 'B']), $unserialized);
    }

    public function testWillKeepContextAttachedToDataWhenSerialized(): void
    {
        $object = LazyData::from('Hello world')->include('name');

        $unserialized = unserialize(serialize($object));

        $this->assertInstanceOf(LazyData::class, $unserialized);
        $this->assertSame(['name' => 'Hello world'], $unserialized->toArray());
    }

    public function testIsPossibleToAddPartialsWithClosuresAndSerializeThem(): void
    {
        $object = LazyData::from('Hello world')->includeWhen(
            'name',
            fn (LazyData $data): bool => $data->name instanceof DefaultLazy
        );

        $unserialized = unserialize(serialize($object));

        $this->assertInstanceOf(LazyData::class, $unserialized);
        $this->assertSame(['name' => 'Hello world'], $unserialized->toArray());
    }

    public function testIsPossibleToSerializeConditionalLazyProperties(): void
    {
        $object = new LazyData(Lazy::when(
            fn (): bool => true,
            fn (): string => 'Hello world'
        ));

        $unserialized = unserialize(serialize($object));

        $this->assertInstanceOf(LazyData::class, $unserialized);
        $this->assertSame(['name' => 'Hello world'], $unserialized->toArray());
    }

    public function testCanJsonEncodeExceptionTraceWithArgsWhenNotAllRequiredPropertiesPassed(): void
    {
        // With zend.exception_ignore_args off, the trace contains the arguments, and all of them must encode.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            SimpleData::from([]);
            $this->fail('Expected the missing property to fail creation.');
        } catch (CannotCreateData $exception) {
            $this->assertJson(json_encode($exception->getTrace(), JSON_THROW_ON_ERROR));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    /**
     * Show the NUL bytes around protected property names as ~.
     */
    protected function readable(string $serialized): string
    {
        return str_replace("\0", '~', $serialized);
    }
}
