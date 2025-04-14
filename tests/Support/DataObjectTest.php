<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\DataObject;
use Hypervel\Support\Str;
use LogicException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class DataObjectTest extends TestCase
{
    /**
     * Test creating a data object with valid data.
     */
    public function testMakeWithValidData(): void
    {
        $data = [
            'string_value' => 'test',
            'int_value' => '42', // String that should be converted to int
            'float_value' => '3.14', // String that should be converted to float
            'bool_value' => 1, // Int that should be converted to bool
            'array_value' => ['item1', 'item2'],
            'object_value' => new stdClass(),
        ];

        $object = TestDataObject::make($data);

        $this->assertInstanceOf(TestDataObject::class, $object);
        $this->assertSame('test', $object->stringValue);
        $this->assertSame(42, $object->intValue);
        $this->assertSame(3.14, $object->floatValue);
        $this->assertTrue($object->boolValue);
        $this->assertSame(['item1', 'item2'], $object->arrayValue);
        $this->assertInstanceOf(stdClass::class, $object->objectValue);
        $this->assertSame('default value', $object->withDefaultValue);
        $this->assertNull($object->nullableValue);
    }

    /**
     * Test creating a data object with massing data.
     */
    public function testMakeWithMissingConfig(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required property `stringValue` in `Hypervel\Tests\Support\TestDataObject`');

        TestDataObject::make([]);
    }

    /**
     * Test overriding the default conversion functions.
     */
    public function testOverrideConvertFunctions(): void
    {
        $data = [
            'snakeCaseParam' => 'foo',
            'multiWordParameterName' => 'bar',
        ];

        $object = TestOverrideDataObject::make($data);

        $this->assertSame('foo', $object->snake_case_param);
        $this->assertSame('bar', $object->multi_word_parameter_name);
    }

    /**
     * Test type conversion for different parameter types.
     */
    public function testTypeConversion(): void
    {
        $data = [
            'string_value' => 123, // Int that should be converted to string
            'int_value' => '42.99', // String that should be converted to int (truncated)
            'float_value' => '3.14', // String that should be converted to float
            'bool_value' => '0', // String that should be converted to bool
            'array_value' => 'single item', // String that should be wrapped in array
        ];

        $object = TestDataObject::make($data);

        $this->assertSame('123', $object->stringValue);
        $this->assertSame(42, $object->intValue);
        $this->assertSame(3.14, $object->floatValue);
        $this->assertFalse($object->boolValue);
        $this->assertSame(['single item'], $object->arrayValue);
    }

    /**
     * Test ArrayAccess implementation - offsetExists and offsetGet.
     */
    public function testArrayAccess(): void
    {
        $object = TestDataObject::make($this->getData());

        // Test offsetExists
        $this->assertTrue(isset($object['string_value']));
        $this->assertTrue(isset($object['int_value']));
        $this->assertFalse(isset($object['non_existent']));

        // Test offsetGet
        $this->assertSame('test', $object['string_value']);
        $this->assertSame(42, $object['int_value']);

        // Test accessing properties that don't exist
        $this->expectException(OutOfBoundsException::class);
        $object['non_existent'];
    }

    /**
     * Test the immutability of DataObject - offsetSet and offsetUnset.
     */
    public function testImmutability(): void
    {
        $object = TestDataObject::make($this->getData());

        // Test offsetSet
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Data object may not be mutated using array access.');
        $object['string_value'] = 'changed';
    }

    /**
     * Test offsetUnset throws exception.
     */
    public function testOffsetUnset(): void
    {
        $object = TestDataObject::make($this->getData());

        // Test offsetUnset
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Data object may not be mutated using array access.');
        unset($object['string_value']);
    }

    /**
     * Test toArray method.
     */
    public function testToArray(): void
    {
        $object = TestDataObject::make($this->getData());
        $array = $object->toArray();

        $this->assertIsArray($array);
        $this->assertSame('test', $array['string_value']);
        $this->assertSame(42, $array['int_value']);
        $this->assertSame(['item1', 'item2'], $array['array_value']);
    }

    /**
     * Test JsonSerialize implementation.
     */
    public function testJsonSerialize(): void
    {
        $object = TestDataObject::make($this->getData());
        $json = json_encode($object);
        $decoded = json_decode($json, true);

        $this->assertIsString($json);
        $this->assertIsArray($decoded);
        $this->assertSame('test', $decoded['string_value']);
        $this->assertSame(42, $decoded['int_value']);
        $this->assertSame(['item1', 'item2'], $decoded['array_value']);
    }

    /**
     * Test nested DataObject serialization.
     */
    public function testNestedObjectSerialization(): void
    {
        $nestedObject = TestDataObject::make(
            array_merge($this->getData(), [
                'string_value' => 'nested',
            ])
        );

        $object = TestDataObject::make(
            array_merge($this->getData(), [
                'string_value' => 'parent',
                'object_value' => $nestedObject,
            ])
        );

        // Test toArray with nested objects
        $array = $object->toArray();
        $this->assertIsArray($array);
        $this->assertIsArray($array['object_value']);
        $this->assertSame('nested', $array['object_value']['string_value']);

        // Test JSON serialization with nested objects
        $json = json_encode($object);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded['object_value']);
        $this->assertSame('nested', $decoded['object_value']['string_value']);
    }

    protected function getData(): array
    {
        return [
            'string_value' => 'test',
            'int_value' => 42,
            'float_value' => 3.14,
            'bool_value' => true,
            'array_value' => ['item1', 'item2'],
            'object_value' => new stdClass(),
        ];
    }
}

/**
 * Concrete implementation of DataObject for testing.
 */
class TestDataObject extends DataObject
{
    public function __construct(
        public string $stringValue,
        public int $intValue,
        public float $floatValue,
        public bool $boolValue,
        public array $arrayValue,
        public ?object $objectValue,
        public string $withDefaultValue = 'default value',
        public ?string $nullableValue = null
    ) {
    }
}

/**
 * Concrete implementation of DataObject for testing.
 */
class TestOverrideDataObject extends DataObject
{
    public function __construct(
        public string $snake_case_param,
        public string $multi_word_parameter_name,
    ) {
    }

    /**
     * Convert the parameter name to the data key format.
     * It converts camelCase to snake_case by default.
     */
    protected static function convertDataKeyToProperty(string $input): string
    {
        return Str::camel($input);
    }

    /**
     * Convert the property name to the data key format.
     * It converts snake_case to camelCase by default.
     */
    protected static function convertPropertyToDataKey(string $input): string
    {
        return Str::snake($input);
    }
}
