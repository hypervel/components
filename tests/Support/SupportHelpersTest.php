<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use ArrayAccess;
use ArrayIterator;
use Carbon\CarbonInterval;
use Countable;
use Error;
use Exception;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Env;
use Hypervel\Support\Optional;
use Hypervel\Support\Sleep;
use Hypervel\Support\Stringable;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Support\Fixtures\IntBackedEnum;
use Hypervel\Tests\Support\Fixtures\StringBackedEnum;
use Hypervel\Tests\TestCase;
use IteratorAggregate;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Traversable;

class SupportHelpersTest extends TestCase
{
    protected string $tempDirectory;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = ParallelTesting::tempDir('SupportHelpersTest');
        (new Filesystem)->deleteDirectory($this->tempDirectory);
        mkdir($this->tempDirectory, 0777, true);

        SupportLazyClass::$constructorCalled = false;
        SupportLazyClassWithArrayParameter::$constructorCalled = false;
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    public function testE(): void
    {
        $str = 'A \'quote\' is <b>bold</b>';
        $this->assertSame('A &#039;quote&#039; is &lt;b&gt;bold&lt;/b&gt;', e($str));

        $html = m::mock(Htmlable::class);
        $html->expects('toHtml')->andReturn($str);
        $this->assertEquals($str, e($html));
    }

    public function testEWithInvalidCodePoints(): void
    {
        $str = mb_convert_encoding('føø bar', 'ISO-8859-1', 'UTF-8');
        $this->assertSame('f�� bar', e($str));
    }

    public function testEWithEnums(): void
    {
        $enumValue = StringBackedEnum::AdminLabel;
        $this->assertSame('I am &#039;admin&#039;', e($enumValue));

        $enumValue = IntBackedEnum::RoleAdmin;
        $this->assertSame('1', e($enumValue));
    }

    public function testBlank(): void
    {
        $this->assertTrue(blank(null));
        $this->assertTrue(blank(''));
        $this->assertTrue(blank('  '));
        $this->assertTrue(blank(new Stringable('')));
        $this->assertTrue(blank(new Stringable('  ')));
        $this->assertFalse(blank(10));
        $this->assertFalse(blank(true));
        $this->assertFalse(blank(false));
        $this->assertFalse(blank(0));
        $this->assertFalse(blank(0.0));
        $this->assertFalse(blank(new Stringable(' FooBar ')));

        $object = new SupportTestCountable;
        $this->assertTrue(blank($object));
    }

    public function testBlankDoesntJsonSerializeModels(): void
    {
        $model = new class extends Model {
            /**
             * Reject unexpected model serialization.
             */
            public function jsonSerialize(): never
            {
                throw new RuntimeException('Model should not be serialized');
            }
        };

        $this->assertFalse(blank($model));
    }

    public function testClassBasename(): void
    {
        $this->assertSame('Baz', class_basename('Foo\Bar\Baz'));
        $this->assertSame('Baz', class_basename('Baz'));
        // back-slash
        $this->assertSame('Baz', class_basename('\Baz'));
        $this->assertSame('Baz', class_basename('\\\Baz\\'));
        $this->assertSame('Baz', class_basename('\Foo\Bar\Baz\\'));
        $this->assertSame('Baz', class_basename('\Foo/Bar\Baz/'));
        // forward-slash
        $this->assertSame('Baz', class_basename('/Foo/Bar/Baz/'));
        $this->assertSame('Baz', class_basename('/Foo///Bar/Baz//'));
        // accepts objects
        $this->assertSame('stdClass', class_basename(new stdClass));
        // edge-cases
        $this->assertSame('1', class_basename(1));
        $this->assertSame('1', class_basename('1'));
        $this->assertSame('', class_basename(''));
        $this->assertSame('', class_basename('\\'));
        $this->assertSame('', class_basename('\\\\'));
        $this->assertSame('', class_basename('/'));
        $this->assertSame('', class_basename('///'));
        $this->assertSame('..', class_basename('\Foo\Bar\Baz\..\\'));
    }

    public function testWhen(): void
    {
        $this->assertSame('Hello', when(true, 'Hello'));
        $this->assertNull(when(false, 'Hello'));
        $this->assertSame('There', when(1 === 1, 'There')); // strict types
        $this->assertSame('There', when((int) '1' === 1, 'There')); // normalized types
        $this->assertNull(when(1 === 2, 'There'));
        $this->assertNull(when('1', fn (): null => null));
        $this->assertNull(when(0, fn (): null => null));
        $this->assertSame('True', when([1, 2, 3, 4], 'True')); // Array
        $this->assertNull(when([], 'True')); // Empty Array = Falsy
        $this->assertSame('True', when(new stdClass, fn (): string => 'True')); // Object
        $this->assertSame('World', when(false, 'Hello', 'World'));
        $this->assertSame('World', when(1 === 0, 'Hello', 'World')); // strict types
        $this->assertSame('World', when((int) '0' === 1, 'Hello', 'World')); // normalized types
        $this->assertNull(when('', fn (): string => 'There', fn (): null => null));
        $this->assertNull(when(0, fn (): string => 'There', fn (): null => null));
        $this->assertSame('False', when([], 'True', 'False'));  // Empty Array = Falsy
        $this->assertTrue(when(true, fn (bool $value): bool => $value, fn (bool $value): bool => ! $value)); // lazy evaluation
        $this->assertTrue(when(false, fn (bool $value): bool => $value, fn (bool $value): bool => ! $value)); // lazy evaluation
        $this->assertSame('Hello', when(fn (): bool => true, 'Hello')); // lazy evaluation condition
        $this->assertSame('World', when(fn (): bool => false, 'Hello', 'World')); // lazy evaluation condition
    }

    public function testFilled(): void
    {
        $this->assertFalse(filled(null));
        $this->assertFalse(filled(''));
        $this->assertFalse(filled('  '));
        $this->assertFalse(filled(new Stringable('')));
        $this->assertFalse(filled(new Stringable('  ')));
        $this->assertTrue(filled(10));
        $this->assertTrue(filled(true));
        $this->assertTrue(filled(false));
        $this->assertTrue(filled(0));
        $this->assertTrue(filled(0.0));
        $this->assertTrue(filled(new Stringable(' FooBar ')));

        $object = new SupportTestCountable;
        $this->assertFalse(filled($object));
    }

    public function testValue(): void
    {
        $callable = new class {
            /**
             * Return the supplied method arguments.
             */
            public function __call(string $method, array $arguments): array
            {
                return $arguments;
            }
        };

        $this->assertSame($callable, value($callable, 'foo'));
        $this->assertSame('foo', value('foo'));
        $this->assertSame('foo', value(function (): string {
            return 'foo';
        }));
        $this->assertSame('foo', value(function (string $argument): string {
            return $argument;
        }, 'foo'));
    }

    public function testObjectGet(): void
    {
        $class = new stdClass;
        $class->name = new stdClass;
        $class->name->first = 'Taylor';

        $this->assertSame('Taylor', object_get($class, 'name.first'));
        $this->assertSame('Taylor', object_get($class, 'name.first', 'default'));
    }

    public function testObjectGetDefaultValue(): void
    {
        $class = new stdClass;
        $class->name = new stdClass;
        $class->name->first = 'Taylor';

        $this->assertSame('default', object_get($class, 'name.family', 'default'));
        $this->assertNull(object_get($class, 'name.family'));
    }

    public function testObjectGetWhenKeyIsNullOrEmpty(): void
    {
        $object = new stdClass;

        $this->assertEquals($object, object_get($object, null));
        $this->assertEquals($object, object_get($object, false));
        $this->assertEquals($object, object_get($object, ''));
        $this->assertEquals($object, object_get($object, '  '));
    }

    public function testDataHas(): void
    {
        $object = (object) ['users' => ['name' => ['Taylor', 'Otwell']]];
        $array = [(object) ['users' => [(object) ['name' => 'Taylor']]]];
        $dottedArray = ['users' => ['first.name' => 'Taylor', 'middle.name' => null]];
        $arrayAccess = new SupportTestArrayAccess(['price' => 56, 'user' => new SupportTestArrayAccess(['name' => 'John']), 'email' => null]);
        $sameKeyMultiLevel = (object) ['name' => 'Taylor', 'company' => ['name' => 'Hypervel']];
        $plainArray = [1, 2, 3];

        $this->assertTrue(data_has($object, 'users.name.0'));
        $this->assertTrue(data_has($array, '0.users.0.name'));
        $this->assertFalse(data_has($array, '0.users.3'));
        $this->assertFalse(data_has($array, '0.users.3'));
        $this->assertFalse(data_has($array, '0.users.3'));
        $this->assertTrue(data_has($dottedArray, ['users', 'first.name']));
        $this->assertTrue(data_has($dottedArray, ['users', 'middle.name']));
        $this->assertFalse(data_has($dottedArray, ['users', 'last.name']));
        $this->assertTrue(data_has($arrayAccess, 'price'));
        $this->assertTrue(data_has($arrayAccess, 'user.name'));
        $this->assertFalse(data_has($arrayAccess, 'foo'));
        $this->assertFalse(data_has($arrayAccess, 'user.foo'));
        $this->assertFalse(data_has($arrayAccess, 'foo'));
        $this->assertFalse(data_has($arrayAccess, 'user.foo'));
        $this->assertTrue(data_has($arrayAccess, 'email'));
        $this->assertTrue(data_has($sameKeyMultiLevel, 'name'));
        $this->assertTrue(data_has($sameKeyMultiLevel, 'company.name'));
        $this->assertFalse(data_has($sameKeyMultiLevel, 'foo.name'));
        $this->assertTrue(data_has($plainArray, 0));
        $this->assertTrue(data_has($plainArray, '0'));
        $this->assertFalse(data_has($plainArray, 4));
        $this->assertFalse(data_has($plainArray, '4'));
        $this->assertFalse(data_has($plainArray, ''));
        $this->assertFalse(data_has($plainArray, []));
        $this->assertFalse(data_has($plainArray, null));
    }

    public function testDataGet(): void
    {
        $object = (object) ['users' => ['name' => ['Taylor', 'Otwell']]];
        $array = [(object) ['users' => [(object) ['name' => 'Taylor']]]];
        $dottedArray = ['users' => ['first.name' => 'Taylor', 'middle.name' => null]];
        $arrayAccess = new SupportTestArrayAccess(['price' => 56, 'user' => new SupportTestArrayAccess(['name' => 'John']), 'email' => null]);

        $this->assertSame('Taylor', data_get($object, 'users.name.0'));
        $this->assertSame('Taylor', data_get($array, '0.users.0.name'));
        $this->assertNull(data_get($array, '0.users.3'));
        $this->assertSame('Not found', data_get($array, '0.users.3', 'Not found'));
        $this->assertSame('Not found', data_get($array, '0.users.3', function (): string {
            return 'Not found';
        }));
        $this->assertSame('Taylor', data_get($dottedArray, ['users', 'first.name']));
        $this->assertNull(data_get($dottedArray, ['users', 'middle.name']));
        $this->assertSame('Not found', data_get($dottedArray, ['users', 'last.name'], 'Not found'));
        $this->assertEquals(56, data_get($arrayAccess, 'price'));
        $this->assertSame('John', data_get($arrayAccess, 'user.name'));
        $this->assertSame('void', data_get($arrayAccess, 'foo', 'void'));
        $this->assertSame('void', data_get($arrayAccess, 'user.foo', 'void'));
        $this->assertNull(data_get($arrayAccess, 'foo'));
        $this->assertNull(data_get($arrayAccess, 'user.foo'));
        $this->assertNull(data_get($arrayAccess, 'email', 'Not found'));
    }

    public function testDataGetWithNestedArrays(): void
    {
        $array = [
            ['name' => 'taylor', 'email' => 'taylorotwell@gmail.com'],
            ['name' => 'abigail'],
            ['name' => 'dayle'],
        ];
        $arrayIterable = new SupportTestArrayIterable([
            ['name' => 'taylor', 'email' => 'taylorotwell@gmail.com'],
            ['name' => 'abigail'],
            ['name' => 'dayle'],
        ]);

        $this->assertEquals(['taylor', 'abigail', 'dayle'], data_get($array, '*.name'));
        $this->assertEquals(['taylorotwell@gmail.com', null, null], data_get($array, '*.email', 'irrelevant'));

        $this->assertEquals(['taylor', 'abigail', 'dayle'], data_get($arrayIterable, '*.name'));
        $this->assertEquals(['taylorotwell@gmail.com', null, null], data_get($arrayIterable, '*.email', 'irrelevant'));

        $array = [
            'users' => [
                ['first' => 'taylor', 'last' => 'otwell', 'email' => 'taylorotwell@gmail.com'],
                ['first' => 'abigail', 'last' => 'otwell'],
                ['first' => 'dayle', 'last' => 'rees'],
            ],
            'posts' => null,
        ];

        $this->assertEquals(['taylor', 'abigail', 'dayle'], data_get($array, 'users.*.first'));
        $this->assertEquals(['taylorotwell@gmail.com', null, null], data_get($array, 'users.*.email', 'irrelevant'));
        $this->assertSame('not found', data_get($array, 'posts.*.date', 'not found'));
        $this->assertNull(data_get($array, 'posts.*.date'));
    }

    public function testDataGetWithDoubleNestedArraysCollapsesResult(): void
    {
        $array = [
            'posts' => [
                [
                    'comments' => [
                        ['author' => 'taylor', 'likes' => 4],
                        ['author' => 'abigail', 'likes' => 3],
                    ],
                ],
                [
                    'comments' => [
                        ['author' => 'abigail', 'likes' => 2],
                        ['author' => 'dayle'],
                    ],
                ],
                [
                    'comments' => [
                        ['author' => 'dayle'],
                        ['author' => 'taylor', 'likes' => 1],
                    ],
                ],
            ],
        ];

        $this->assertEquals(['taylor', 'abigail', 'abigail', 'dayle', 'dayle', 'taylor'], data_get($array, 'posts.*.comments.*.author'));
        $this->assertEquals([4, 3, 2, null, null, 1], data_get($array, 'posts.*.comments.*.likes'));
        $this->assertSame([], data_get($array, 'posts.*.users.*.name', 'irrelevant'));
        $this->assertSame([], data_get($array, 'posts.*.users.*.name'));
    }

    public function testDataGetFirstLastDirectives(): void
    {
        $array = [
            'flights' => [
                [
                    'segments' => [
                        ['from' => 'LHR', 'departure' => '9:00', 'to' => 'IST', 'arrival' => '15:00'],
                        ['from' => 'IST', 'departure' => '16:00', 'to' => 'PKX', 'arrival' => '20:00'],
                    ],
                ],
                [
                    'segments' => [
                        ['from' => 'LGW', 'departure' => '8:00', 'to' => 'SAW', 'arrival' => '14:00'],
                        ['from' => 'SAW', 'departure' => '15:00', 'to' => 'PEK', 'arrival' => '19:00'],
                    ],
                ],
            ],
            'empty' => [],
        ];

        $this->assertSame('LHR', data_get($array, 'flights.0.segments.{first}.from'));
        $this->assertSame('PKX', data_get($array, 'flights.0.segments.{last}.to'));

        $this->assertSame('LHR', data_get($array, 'flights.{first}.segments.{first}.from'));
        $this->assertSame('PEK', data_get($array, 'flights.{last}.segments.{last}.to'));
        $this->assertSame('PKX', data_get($array, 'flights.{first}.segments.{last}.to'));
        $this->assertSame('LGW', data_get($array, 'flights.{last}.segments.{first}.from'));

        $this->assertEquals(['LHR', 'IST'], data_get($array, 'flights.{first}.segments.*.from'));
        $this->assertEquals(['SAW', 'PEK'], data_get($array, 'flights.{last}.segments.*.to'));

        $this->assertEquals(['LHR', 'LGW'], data_get($array, 'flights.*.segments.{first}.from'));
        $this->assertEquals(['PKX', 'PEK'], data_get($array, 'flights.*.segments.{last}.to'));

        $this->assertSame('Not found', data_get($array, 'empty.{first}', 'Not found'));
        $this->assertSame('Not found', data_get($array, 'empty.{last}', 'Not found'));
    }

    public function testDataGetFirstLastDirectivesOnArrayAccessIterable(): void
    {
        $arrayAccessIterable = [
            'flights' => new SupportTestArrayAccessIterable([
                [
                    'segments' => new SupportTestArrayAccessIterable([
                        ['from' => 'LHR', 'departure' => '9:00', 'to' => 'IST', 'arrival' => '15:00'],
                        ['from' => 'IST', 'departure' => '16:00', 'to' => 'PKX', 'arrival' => '20:00'],
                    ]),
                ],
                [
                    'segments' => new SupportTestArrayAccessIterable([
                        ['from' => 'LGW', 'departure' => '8:00', 'to' => 'SAW', 'arrival' => '14:00'],
                        ['from' => 'SAW', 'departure' => '15:00', 'to' => 'PEK', 'arrival' => '19:00'],
                    ]),
                ],
            ]),
            'empty' => new SupportTestArrayAccessIterable([]),
        ];

        $this->assertSame('LHR', data_get($arrayAccessIterable, 'flights.0.segments.{first}.from'));
        $this->assertSame('PKX', data_get($arrayAccessIterable, 'flights.0.segments.{last}.to'));

        $this->assertSame('LHR', data_get($arrayAccessIterable, 'flights.{first}.segments.{first}.from'));
        $this->assertSame('PEK', data_get($arrayAccessIterable, 'flights.{last}.segments.{last}.to'));
        $this->assertSame('PKX', data_get($arrayAccessIterable, 'flights.{first}.segments.{last}.to'));
        $this->assertSame('LGW', data_get($arrayAccessIterable, 'flights.{last}.segments.{first}.from'));

        $this->assertEquals(['LHR', 'IST'], data_get($arrayAccessIterable, 'flights.{first}.segments.*.from'));
        $this->assertEquals(['SAW', 'PEK'], data_get($arrayAccessIterable, 'flights.{last}.segments.*.to'));

        $this->assertEquals(['LHR', 'LGW'], data_get($arrayAccessIterable, 'flights.*.segments.{first}.from'));
        $this->assertEquals(['PKX', 'PEK'], data_get($arrayAccessIterable, 'flights.*.segments.{last}.to'));

        $this->assertSame('Not found', data_get($arrayAccessIterable, 'empty.{first}', 'Not found'));
        $this->assertSame('Not found', data_get($arrayAccessIterable, 'empty.{last}', 'Not found'));
    }

    public function testDataGetFirstLastDirectivesOnKeyedArrays(): void
    {
        $array = [
            'numericKeys' => [
                2 => 'first',
                0 => 'second',
                1 => 'last',
            ],
            'stringKeys' => [
                'one' => 'first',
                'two' => 'second',
                'three' => 'last',
            ],
        ];

        $this->assertSame('second', data_get($array, 'numericKeys.0'));
        $this->assertSame('first', data_get($array, 'numericKeys.{first}'));
        $this->assertSame('last', data_get($array, 'numericKeys.{last}'));
        $this->assertSame('first', data_get($array, 'stringKeys.{first}'));
        $this->assertSame('last', data_get($array, 'stringKeys.{last}'));
    }

    public function testDataGetEscapedSegmentKeys(): void
    {
        $array = [
            'symbols' => [
                '{last}' => ['description' => 'dollar'],
                '*' => ['description' => 'asterisk'],
                '{first}' => ['description' => 'caret'],
            ],
        ];

        $this->assertSame('caret', data_get($array, 'symbols.\{first}.description'));
        $this->assertSame('dollar', data_get($array, 'symbols.{first}.description'));
        $this->assertSame('asterisk', data_get($array, 'symbols.\*.description'));
        $this->assertEquals(['dollar', 'asterisk', 'caret'], data_get($array, 'symbols.*.description'));
        $this->assertSame('dollar', data_get($array, 'symbols.\{last}.description'));
        $this->assertSame('caret', data_get($array, 'symbols.{last}.description'));
    }

    public function testDataGetStar(): void
    {
        $data = ['foo' => 'bar'];
        $this->assertEquals(['bar'], data_get($data, '*'));

        $data = collect(['foo' => 'bar']);
        $this->assertEquals(['bar'], data_get($data, '*'));
    }

    public function testDataGetNullKey(): void
    {
        $data = ['foo' => 'bar'];

        $this->assertEquals(['foo' => 'bar'], data_get($data, null));
        $this->assertEquals(['foo' => 'bar'], data_get($data, null, '42'));
        $this->assertEquals(['foo' => 'bar'], data_get($data, [null]));

        $data = ['foo' => 'bar', 'baz' => 42];
        $this->assertEquals(['foo' => 'bar', 'baz' => 42], data_get($data, [null, 'foo']));
    }

    public function testDataFill(): void
    {
        $data = ['foo' => 'bar'];

        $this->assertEquals(['foo' => 'bar', 'baz' => 'boom'], data_fill($data, 'baz', 'boom'));
        $this->assertEquals(['foo' => 'bar', 'baz' => 'boom'], data_fill($data, 'baz', 'noop'));
        $this->assertEquals(['foo' => [], 'baz' => 'boom'], data_fill($data, 'foo.*', 'noop'));
        $this->assertEquals(
            ['foo' => ['bar' => 'kaboom'], 'baz' => 'boom'],
            data_fill($data, 'foo.bar', 'kaboom')
        );
    }

    public function testDataFillWithStar(): void
    {
        $data = ['foo' => 'bar'];

        $this->assertEquals(
            ['foo' => []],
            data_fill($data, 'foo.*.bar', 'noop')
        );

        $this->assertEquals(
            ['foo' => [], 'bar' => [['baz' => 'original'], []]],
            data_fill($data, 'bar', [['baz' => 'original'], []])
        );

        $this->assertEquals(
            ['foo' => [], 'bar' => [['baz' => 'original'], ['baz' => 'boom']]],
            data_fill($data, 'bar.*.baz', 'boom')
        );

        $this->assertEquals(
            ['foo' => [], 'bar' => [['baz' => 'original'], ['baz' => 'boom']]],
            data_fill($data, 'bar.*', 'noop')
        );
    }

    public function testDataFillWithDoubleStar(): void
    {
        $data = [
            'posts' => [
                (object) [
                    'comments' => [
                        (object) ['name' => 'First'],
                        (object) [],
                    ],
                ],
                (object) [
                    'comments' => [
                        (object) [],
                        (object) ['name' => 'Second'],
                    ],
                ],
            ],
        ];

        data_fill($data, 'posts.*.comments.*.name', 'Filled');

        $this->assertEquals([
            'posts' => [
                (object) [
                    'comments' => [
                        (object) ['name' => 'First'],
                        (object) ['name' => 'Filled'],
                    ],
                ],
                (object) [
                    'comments' => [
                        (object) ['name' => 'Filled'],
                        (object) ['name' => 'Second'],
                    ],
                ],
            ],
        ], $data);
    }

    public function testDataSet(): void
    {
        $data = ['foo' => 'bar'];

        $this->assertEquals(
            ['foo' => 'bar', 'baz' => 'boom'],
            data_set($data, 'baz', 'boom')
        );

        $this->assertEquals(
            ['foo' => 'bar', 'baz' => 'kaboom'],
            data_set($data, 'baz', 'kaboom')
        );

        $this->assertEquals(
            ['foo' => [], 'baz' => 'kaboom'],
            data_set($data, 'foo.*', 'noop')
        );

        $this->assertEquals(
            ['foo' => ['bar' => 'boom'], 'baz' => 'kaboom'],
            data_set($data, 'foo.bar', 'boom')
        );

        $this->assertEquals(
            ['foo' => ['bar' => 'boom'], 'baz' => ['bar' => 'boom']],
            data_set($data, 'baz.bar', 'boom')
        );

        $this->assertEquals(
            ['foo' => ['bar' => 'boom'], 'baz' => ['bar' => ['boom' => ['kaboom' => 'boom']]]],
            data_set($data, 'baz.bar.boom.kaboom', 'boom')
        );
    }

    public function testDataSetWithStar(): void
    {
        $data = ['foo' => 'bar'];

        $this->assertEquals(
            ['foo' => []],
            data_set($data, 'foo.*.bar', 'noop')
        );

        $this->assertEquals(
            ['foo' => [], 'bar' => [['baz' => 'original'], []]],
            data_set($data, 'bar', [['baz' => 'original'], []])
        );

        $this->assertEquals(
            ['foo' => [], 'bar' => [['baz' => 'boom'], ['baz' => 'boom']]],
            data_set($data, 'bar.*.baz', 'boom')
        );

        $this->assertEquals(
            ['foo' => [], 'bar' => ['overwritten', 'overwritten']],
            data_set($data, 'bar.*', 'overwritten')
        );
    }

    public function testDataSetWithDoubleStar(): void
    {
        $data = [
            'posts' => [
                (object) [
                    'comments' => [
                        (object) ['name' => 'First'],
                        (object) [],
                    ],
                ],
                (object) [
                    'comments' => [
                        (object) [],
                        (object) ['name' => 'Second'],
                    ],
                ],
            ],
        ];

        data_set($data, 'posts.*.comments.*.name', 'Filled');

        $this->assertEquals([
            'posts' => [
                (object) [
                    'comments' => [
                        (object) ['name' => 'Filled'],
                        (object) ['name' => 'Filled'],
                    ],
                ],
                (object) [
                    'comments' => [
                        (object) ['name' => 'Filled'],
                        (object) ['name' => 'Filled'],
                    ],
                ],
            ],
        ], $data);
    }

    public function testDataRemove(): void
    {
        $data = ['foo' => 'bar', 'hello' => 'world'];

        $this->assertEquals(
            ['hello' => 'world'],
            data_forget($data, 'foo')
        );

        $data = ['foo' => 'bar', 'hello' => 'world'];

        $this->assertEquals(
            ['foo' => 'bar', 'hello' => 'world'],
            data_forget($data, 'nothing')
        );

        $data = ['one' => ['two' => ['three' => 'hello', 'four' => ['five']]]];

        $this->assertEquals(
            ['one' => ['two' => ['four' => ['five']]]],
            data_forget($data, 'one.two.three')
        );
    }

    public function testDataRemoveWithStar(): void
    {
        $data = [
            'article' => [
                'title' => 'Foo',
                'comments' => [
                    ['comment' => 'foo', 'name' => 'First'],
                    ['comment' => 'bar', 'name' => 'Second'],
                ],
            ],
        ];

        $this->assertEquals(
            [
                'article' => [
                    'title' => 'Foo',
                    'comments' => [
                        ['comment' => 'foo'],
                        ['comment' => 'bar'],
                    ],
                ],
            ],
            data_forget($data, 'article.comments.*.name')
        );
    }

    public function testDataRemoveWithDoubleStar(): void
    {
        $data = [
            'posts' => [
                (object) [
                    'comments' => [
                        (object) ['name' => 'First', 'comment' => 'foo'],
                        (object) ['name' => 'Second', 'comment' => 'bar'],
                    ],
                ],
                (object) [
                    'comments' => [
                        (object) ['name' => 'Third', 'comment' => 'hello'],
                        (object) ['name' => 'Fourth', 'comment' => 'world'],
                    ],
                ],
            ],
        ];

        data_forget($data, 'posts.*.comments.*.name');

        $this->assertEquals([
            'posts' => [
                (object) [
                    'comments' => [
                        (object) ['comment' => 'foo'],
                        (object) ['comment' => 'bar'],
                    ],
                ],
                (object) [
                    'comments' => [
                        (object) ['comment' => 'hello'],
                        (object) ['comment' => 'world'],
                    ],
                ],
            ],
        ], $data);
    }

    public function testHead(): void
    {
        $array = ['a', 'b', 'c'];
        $this->assertSame('a', head($array));
    }

    public function testLast(): void
    {
        $array = ['a', 'b', 'c'];
        $this->assertSame('c', last($array));
    }

    public function testClassUsesRecursiveShouldReturnTraitsOnParentClasses(): void
    {
        $this->assertSame(
            [
                SupportTestTraitTwo::class => SupportTestTraitTwo::class,
                SupportTestTraitOne::class => SupportTestTraitOne::class,
            ],
            class_uses_recursive(SupportTestClassTwo::class)
        );
    }

    public function testClassUsesRecursiveAcceptsObject(): void
    {
        $this->assertSame(
            [
                SupportTestTraitTwo::class => SupportTestTraitTwo::class,
                SupportTestTraitOne::class => SupportTestTraitOne::class,
            ],
            class_uses_recursive(new SupportTestClassTwo)
        );
    }

    public function testClassUsesRecursiveReturnParentTraitsFirst(): void
    {
        $this->assertSame(
            [
                SupportTestTraitTwo::class => SupportTestTraitTwo::class,
                SupportTestTraitOne::class => SupportTestTraitOne::class,
                SupportTestTraitThree::class => SupportTestTraitThree::class,
            ],
            class_uses_recursive(SupportTestClassThree::class)
        );
    }

    public function testTraitUsesRecursive(): void
    {
        $this->assertSame(
            [
                SupportTestTraitTwo::class => SupportTestTraitTwo::class,
                SupportTestTraitOne::class => SupportTestTraitOne::class,
            ],
            trait_uses_recursive(SupportTestClassOne::class)
        );

        $this->assertSame([], trait_uses_recursive(SupportTestClassTwo::class));
    }

    public function testStr(): void
    {
        $stringable = str('string-value');

        $this->assertInstanceOf(Stringable::class, $stringable);
        $this->assertSame('string-value', (string) $stringable);

        $stringable = str($name = null);
        $this->assertInstanceOf(Stringable::class, $stringable);
        $this->assertTrue($stringable->isEmpty());

        $strAccessor = str();
        $this->assertTrue((new ReflectionClass($strAccessor))->isAnonymous());
        $this->assertSame('str...', $strAccessor->limit('string-value', 3));

        $strAccessor = str();
        $this->assertTrue((new ReflectionClass($strAccessor))->isAnonymous());
        $this->assertSame('', (string) $strAccessor);
    }

    public function testTap(): void
    {
        $object = (object) ['id' => 1];
        $this->assertEquals(2, tap($object, function (stdClass $object): void {
            $object->id = 2;
        })->id);

        $mock = m::mock();
        $mock->expects('foo')->andReturn('bar');
        $this->assertEquals($mock, tap($mock)->foo());
    }

    public function testThrow(): void
    {
        $this->expectException(LogicException::class);

        throw_if(true, new LogicException);
    }

    public function testThrowDefaultException(): void
    {
        $this->expectException(RuntimeException::class);

        throw_if(true);
    }

    public function testThrowExceptionWithMessage(): void
    {
        $this->expectExceptionObject(new RuntimeException('test'));

        throw_if(true, 'test');
    }

    public function testThrowExceptionAsStringWithMessage(): void
    {
        $this->expectExceptionObject(new LogicException('test'));

        throw_if(true, LogicException::class, 'test');
    }

    public function testThrowClosureException(): void
    {
        $this->expectExceptionObject(new Exception('test'));

        throw_if(true, fn (): Exception => new Exception('test'));
    }

    public function testThrowClosureWithParamsException(): void
    {
        $this->expectExceptionObject(new Exception('test'));

        throw_if(true, fn (string $message): Exception => new Exception($message), 'test');
    }

    public function testThrowClosureStringWithParamsException(): void
    {
        $this->expectExceptionObject(new Exception('test'));

        throw_if(true, fn (): string => Exception::class, 'test');
    }

    public function testThrowUnless(): void
    {
        $this->expectException(LogicException::class);

        throw_unless(false, new LogicException);
    }

    public function testThrowUnlessDefaultException(): void
    {
        $this->expectException(RuntimeException::class);

        throw_unless(false);
    }

    public function testThrowUnlessExceptionWithMessage(): void
    {
        $this->expectExceptionObject(new RuntimeException('test'));

        throw_unless(false, 'test');
    }

    public function testThrowUnlessExceptionAsStringWithMessage(): void
    {
        $this->expectExceptionObject(new LogicException('test'));

        throw_unless(false, LogicException::class, 'test');
    }

    public function testThrowReturnIfNotThrown(): void
    {
        $this->assertSame('foo', throw_unless('foo', new RuntimeException));
    }

    public function testThrowWithString(): void
    {
        $this->expectExceptionObject(new RuntimeException('Test Message'));

        throw_if(true, RuntimeException::class, 'Test Message');
    }

    public function testOptional(): void
    {
        $this->assertNull(optional(null)->something());

        $this->assertEquals(10, optional(new class {
            /**
             * Return a value through the optional proxy.
             */
            public function something(): int
            {
                return 10;
            }
        })->something());
    }

    public function testOptionalWithCallback(): void
    {
        $this->assertNull(optional(null, function (): never {
            throw new RuntimeException(
                'The optional callback should not be called for null'
            );
        }));

        $this->assertEquals(10, optional(5, function (int $number): int {
            return $number * 2;
        }));
    }

    public function testOptionalWithArray(): void
    {
        $this->assertSame('here', optional(['present' => 'here'])['present']);
        $this->assertNull(optional(null)['missing']);
        $this->assertNull(optional(['present' => 'here'])->missing);
    }

    public function testOptionalReturnsObjectPropertyOrNull(): void
    {
        $this->assertSame('bar', optional((object) ['foo' => 'bar'])->foo);
        $this->assertNull(optional(['foo' => 'bar'])->foo);
        $this->assertNull(optional((object) ['foo' => 'bar'])->bar);
    }

    public function testOptionalDeterminesWhetherKeyIsSet(): void
    {
        $this->assertTrue(isset(optional(['foo' => 'bar'])['foo']));
        $this->assertFalse(isset(optional(['foo' => 'bar'])['bar']));
        $this->assertFalse(isset(optional()['bar']));
    }

    public function testOptionalAllowsToSetKey(): void
    {
        $optional = optional([]);
        $optional['foo'] = 'bar';
        $this->assertSame('bar', $optional['foo']);

        $optional = optional(null);
        $optional['foo'] = 'bar';
        $this->assertFalse(isset($optional['foo']));
    }

    public function testOptionalAllowToUnsetKey(): void
    {
        $optional = optional(['foo' => 'bar']);
        $this->assertTrue(isset($optional['foo']));
        unset($optional['foo']);
        $this->assertFalse(isset($optional['foo']));

        $optional = optional((object) ['foo' => 'bar']);
        $this->assertFalse(isset($optional['foo']));
        $optional['foo'] = 'bar';
        $this->assertFalse(isset($optional['foo']));
    }

    public function testOptionalIsMacroable(): void
    {
        Optional::macro('present', function (): object {
            if (is_object($this->value)) {
                return $this->value->present();
            }

            return new Optional(null);
        });

        $this->assertNull(optional(null)->present()->something());

        $this->assertSame('$10.00', optional(new class {
            /**
             * Return the presentation object.
             */
            public function present(): object
            {
                return new class {
                    /**
                     * Return the formatted value.
                     */
                    public function something(): string
                    {
                        return '$10.00';
                    }
                };
            }
        })->present()->something());
    }

    public function testRetry(): void
    {
        Sleep::fake();

        $attempts = retry(2, function (int $attempts): int {
            if ($attempts > 1) {
                return $attempts;
            }

            throw new RuntimeException;
        }, 100);

        // Make sure we made two attempts
        $this->assertEquals(2, $attempts);

        // Make sure we waited 100ms for the first attempt
        Sleep::assertSleptTimes(1);

        Sleep::assertSequence([
            Sleep::usleep(100_000),
        ]);
    }

    public function testRetryWithCarbonIntervalSleep(): void
    {
        Sleep::fake();

        $attempts = retry(2, function (int $attempts): int {
            if ($attempts > 1) {
                return $attempts;
            }

            throw new RuntimeException;
        }, CarbonInterval::milliseconds(100));

        // Make sure we made two attempts
        $this->assertEquals(2, $attempts);

        // Make sure we waited 100ms for the first attempt
        Sleep::assertSleptTimes(1);

        Sleep::assertSequence([
            Sleep::usleep(100_000),
        ]);
    }

    public function testRetryWithPassingSleepCallback(): void
    {
        Sleep::fake();

        $attempts = retry(3, function (int $attempts): int {
            if ($attempts > 2) {
                return $attempts;
            }

            throw new RuntimeException;
        }, function (int $attempt, RuntimeException $exception): int {
            $this->assertInstanceOf(RuntimeException::class, $exception);

            return $attempt * 100;
        });

        // Make sure we made three attempts
        $this->assertEquals(3, $attempts);

        // Make sure we waited 300ms for the first two attempts
        Sleep::assertSleptTimes(2);

        Sleep::assertSequence([
            Sleep::usleep(100_000),
            Sleep::usleep(200_000),
        ]);
    }

    public function testRetryWithPassingWhenCallback(): void
    {
        Sleep::fake();

        $attempts = retry(2, function (int $attempts): int {
            if ($attempts > 1) {
                return $attempts;
            }

            throw new RuntimeException;
        }, 100, function (RuntimeException $exception): bool {
            return true;
        });

        // Make sure we made two attempts
        $this->assertEquals(2, $attempts);

        // Make sure we waited 100ms for the first attempt
        Sleep::assertSleptTimes(1);

        Sleep::assertSequence([
            Sleep::usleep(100_000),
        ]);
    }

    public function testRetryWithFailingWhenCallback(): void
    {
        $this->expectException(RuntimeException::class);

        retry(2, function (int $attempts): int {
            if ($attempts > 1) {
                return $attempts;
            }

            throw new RuntimeException;
        }, 100, function (RuntimeException $exception): bool {
            return false;
        });
    }

    public function testRetryWithBackoff(): void
    {
        Sleep::fake();

        $attempts = retry([50, 100, 200], function (int $attempts): int {
            if ($attempts > 3) {
                return $attempts;
            }

            throw new RuntimeException;
        });

        // Make sure we made four attempts
        $this->assertEquals(4, $attempts);

        Sleep::assertSleptTimes(3);

        Sleep::assertSequence([
            Sleep::usleep(50_000),
            Sleep::usleep(100_000),
            Sleep::usleep(200_000),
        ]);
    }

    public function testRetryWithAThrowableBase(): void
    {
        Sleep::fake();

        $attempts = retry(2, function (int $attempts): int {
            if ($attempts > 1) {
                return $attempts;
            }

            throw new Error('This is an error');
        }, 100);

        // Make sure we made two attempts
        $this->assertEquals(2, $attempts);

        // Make sure we waited 100ms for the first attempt
        Sleep::assertSleptTimes(1);

        Sleep::assertSequence([
            Sleep::usleep(100_000),
        ]);
    }

    public function testRetryWithFractionalSleep(): void
    {
        Sleep::fake();

        $attempts = retry(2, function (int $attempts): int {
            if ($attempts > 1) {
                return $attempts;
            }

            throw new RuntimeException;
        }, 100.5);

        $this->assertSame(2, $attempts);
        Sleep::assertSequence([Sleep::usleep(100_500)]);
    }

    public function testCancellationIsNotRetriedOrPassedToRetryPolicy(): void
    {
        $cancellation = new CanceledException('operation cancelled');
        $attempts = 0;
        $policyCalled = false;
        $sleepCalled = false;

        try {
            retry(
                3,
                function () use (&$attempts, $cancellation): never {
                    ++$attempts;

                    throw $cancellation;
                },
                function () use (&$sleepCalled): int {
                    $sleepCalled = true;

                    return 1;
                },
                function () use (&$policyCalled): bool {
                    $policyCalled = true;

                    return true;
                },
            );
            $this->fail('Expected the operation cancellation to be thrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(1, $attempts);
        $this->assertFalse($policyCalled);
        $this->assertFalse($sleepCalled);
    }

    public function testTransform(): void
    {
        $this->assertEquals(10, transform(5, function (int $value): int {
            return $value * 2;
        }));

        $this->assertNull(transform(null, function (): int {
            return 10;
        }));
    }

    public function testTransformDefaultWhenBlank(): void
    {
        $this->assertSame('baz', transform(null, function (): string {
            return 'bar';
        }, 'baz'));

        $this->assertSame('baz', transform('', function (): string {
            return 'bar';
        }, function (): string {
            return 'baz';
        }));
    }

    public function testWith(): void
    {
        $this->assertEquals(10, with(10));

        $this->assertEquals(10, with(5, function (int $five): int {
            return $five + 5;
        }));
    }

    public function testAppendConfig(): void
    {
        $this->assertSame([10000 => 'name', 10001 => 'family'], append_config([1 => 'name', 2 => 'family']));
        $this->assertSame([10000 => 'name', 10001 => 'family'], append_config(['name', 'family']));

        $array = ['name' => 'Taylor', 'family' => 'Otwell'];
        $this->assertSame($array, append_config($array));
    }

    public function testEnv(): void
    {
        $this->withEnvironmentValue('foo', null, function (): void {
            $_SERVER['foo'] = 'bar';
            $this->assertSame('bar', env('foo'));
            $this->assertSame('bar', Env::get('foo'));
        });
    }

    public function testEnvTrue(): void
    {
        $this->withEnvironmentValue('foo', null, function (): void {
            $_SERVER['foo'] = 'true';
            $this->assertTrue(env('foo'));

            $_SERVER['foo'] = '(true)';
            $this->assertTrue(env('foo'));
        });
    }

    public function testEnvFalse(): void
    {
        $this->withEnvironmentValue('foo', null, function (): void {
            $_SERVER['foo'] = 'false';
            $this->assertFalse(env('foo'));

            $_SERVER['foo'] = '(false)';
            $this->assertFalse(env('foo'));
        });
    }

    public function testEnvEmpty(): void
    {
        $this->withEnvironmentValue('foo', null, function (): void {
            $_SERVER['foo'] = '';
            $this->assertSame('', env('foo'));

            $_SERVER['foo'] = 'empty';
            $this->assertSame('', env('foo'));

            $_SERVER['foo'] = '(empty)';
            $this->assertSame('', env('foo'));
        });
    }

    public function testEnvNull(): void
    {
        $this->withEnvironmentValue('foo', null, function (): void {
            $_SERVER['foo'] = 'null';
            $this->assertNull(env('foo'));

            $_SERVER['foo'] = '(null)';
            $this->assertNull(env('foo'));
        });
    }

    public function testEnvDefault(): void
    {
        $this->withEnvironmentValue('foo', null, function (): void {
            $_SERVER['foo'] = 'bar';
            $this->assertSame('bar', env('foo', 'default'));

            $_SERVER['foo'] = '';
            $this->assertSame('', env('foo', 'default'));

            unset($_SERVER['foo']);
            $this->assertSame('default', env('foo', 'default'));

            $_SERVER['foo'] = null;
            $this->assertSame('default', env('foo', 'default'));
        });
    }

    public function testEnvEscapedString(): void
    {
        $this->withEnvironmentValue('foo', null, function (): void {
            $_SERVER['foo'] = '"null"';
            $this->assertSame('null', env('foo'));

            $_SERVER['foo'] = "'null'";
            $this->assertSame('null', env('foo'));

            $_SERVER['foo'] = 'x"null"x'; // this should not be unquoted
            $this->assertSame('x"null"x', env('foo'));
        });
    }

    public function testWriteArrayOfEnvVariablesToFile(): void
    {
        $filesystem = new Filesystem;
        $path = $this->tempDirectory . '/env-test-file';
        $filesystem->put($path, implode(PHP_EOL, [
            'APP_NAME=Hypervel',
            'APP_ENV=local',
            'APP_KEY=base64:randomkey',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            '',
            'DB_CONNECTION=mysql',
            'DB_HOST=',
        ]));

        Env::writeVariables([
            'APP_VIBE' => 'chill',
            'DB_HOST' => '127:0:0:1',
            'DB_PORT' => 3306,
            'BRAND_NEW_PREFIX' => 'fresh value',
        ], $path);

        $this->assertSame(
            implode(PHP_EOL, [
                'APP_NAME=Hypervel',
                'APP_ENV=local',
                'APP_KEY=base64:randomkey',
                'APP_DEBUG=true',
                'APP_URL=http://localhost',
                'APP_VIBE=chill',
                '',
                'DB_CONNECTION=mysql',
                'DB_HOST="127:0:0:1"',
                'DB_PORT=3306',
                '',
                'BRAND_NEW_PREFIX="fresh value"',
            ]),
            $filesystem->get($path)
        );
    }

    public function testWriteArrayOfEnvVariablesToFileAndOverwrite(): void
    {
        $filesystem = new Filesystem;
        $path = $this->tempDirectory . '/env-test-file';
        $filesystem->put($path, implode(PHP_EOL, [
            'APP_NAME=Hypervel',
            'APP_ENV=local',
            'APP_KEY=base64:randomkey',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            '',
            'DB_CONNECTION=mysql',
            'DB_HOST=',
        ]));

        Env::writeVariables([
            'APP_VIBE' => 'chill',
            'DB_HOST' => '127:0:0:1',
            'DB_CONNECTION' => 'sqlite',
        ], $path, true);

        $this->assertSame(
            implode(PHP_EOL, [
                'APP_NAME=Hypervel',
                'APP_ENV=local',
                'APP_KEY=base64:randomkey',
                'APP_DEBUG=true',
                'APP_URL=http://localhost',
                'APP_VIBE=chill',
                '',
                'DB_CONNECTION=sqlite',
                'DB_HOST="127:0:0:1"',
            ]),
            $filesystem->get($path)
        );
    }

    public function testWillNotOverwriteArrayOfVariables(): void
    {
        $filesystem = new Filesystem;
        $path = $this->tempDirectory . '/env-test-file';
        $filesystem->put($path, implode(PHP_EOL, [
            'APP_NAME=Hypervel',
            'APP_ENV=local',
            'APP_KEY=base64:randomkey',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            'APP_VIBE=odd',
            '',
            'DB_CONNECTION=mysql',
            'DB_HOST=',
        ]));

        Env::writeVariables([
            'APP_VIBE' => 'chill',
            'DB_HOST' => '127:0:0:1',
        ], $path);

        $this->assertSame(
            implode(PHP_EOL, [
                'APP_NAME=Hypervel',
                'APP_ENV=local',
                'APP_KEY=base64:randomkey',
                'APP_DEBUG=true',
                'APP_URL=http://localhost',
                'APP_VIBE=odd',
                '',
                'DB_CONNECTION=mysql',
                'DB_HOST="127:0:0:1"',
            ]),
            $filesystem->get($path)
        );
    }

    public function testWriteVariableToFile(): void
    {
        $filesystem = new Filesystem;
        $path = $this->tempDirectory . '/env-test-file';
        $filesystem->put($path, implode(PHP_EOL, [
            'APP_NAME=Hypervel',
            'APP_ENV=local',
            'APP_KEY=base64:randomkey',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            '',
            'DB_CONNECTION=mysql',
            'DB_HOST=',
        ]));

        Env::writeVariable('APP_VIBE', 'chill', $path);

        $this->assertSame(
            implode(PHP_EOL, [
                'APP_NAME=Hypervel',
                'APP_ENV=local',
                'APP_KEY=base64:randomkey',
                'APP_DEBUG=true',
                'APP_URL=http://localhost',
                'APP_VIBE=chill',
                '',
                'DB_CONNECTION=mysql',
                'DB_HOST=',
            ]),
            $filesystem->get($path)
        );
    }

    public function testWillNotOverwriteVariable(): void
    {
        $filesystem = new Filesystem;
        $path = $this->tempDirectory . '/env-test-file';
        $filesystem->put($path, implode(PHP_EOL, [
            'APP_NAME=Hypervel',
            'APP_ENV=local',
            'APP_KEY=base64:randomkey',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            'APP_VIBE=odd',
            '',
            'DB_CONNECTION=mysql',
            'DB_HOST=',
        ]));

        Env::writeVariable('APP_VIBE', 'chill', $path);

        $this->assertSame(
            implode(PHP_EOL, [
                'APP_NAME=Hypervel',
                'APP_ENV=local',
                'APP_KEY=base64:randomkey',
                'APP_DEBUG=true',
                'APP_URL=http://localhost',
                'APP_VIBE=odd',
                '',
                'DB_CONNECTION=mysql',
                'DB_HOST=',
            ]),
            $filesystem->get($path)
        );
    }

    public function testWriteVariableToFileAndOverwrite(): void
    {
        $filesystem = new Filesystem;
        $path = $this->tempDirectory . '/env-test-file';
        $filesystem->put($path, implode(PHP_EOL, [
            'APP_NAME=Hypervel',
            'APP_ENV=local',
            'APP_KEY=base64:randomkey',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            'APP_VIBE=odd',
            '',
            'DB_CONNECTION=mysql',
            'DB_HOST=',
        ]));

        Env::writeVariable('APP_VIBE', 'chill', $path, true);

        $this->assertSame(
            implode(PHP_EOL, [
                'APP_NAME=Hypervel',
                'APP_ENV=local',
                'APP_KEY=base64:randomkey',
                'APP_DEBUG=true',
                'APP_URL=http://localhost',
                'APP_VIBE=chill',
                '',
                'DB_CONNECTION=mysql',
                'DB_HOST=',
            ]),
            $filesystem->get($path)
        );
    }

    public function testWriteVariableQuotesValuesWithSpecialCharacters(): void
    {
        $filesystem = new Filesystem;
        $path = $this->tempDirectory . '/env-test-file';
        $filesystem->put($path, 'APP_NAME=Hypervel' . PHP_EOL);

        Env::writeVariable('APP_BRACKET', 'pass[word', $path);
        Env::writeVariable('APP_CARET', 'foo^bar', $path);
        Env::writeVariable('APP_BACKTICK', 'foo`bar', $path);

        $contents = $filesystem->get($path);

        $this->assertStringContainsString('APP_BRACKET="pass[word"', $contents);
        $this->assertStringContainsString('APP_CARET="foo^bar"', $contents);
        $this->assertStringContainsString('APP_BACKTICK="foo`bar"', $contents);
    }

    public function testWillThrowAnExceptionIfFileIsMissingWhenTryingToWriteVariables(): void
    {
        $this->expectExceptionObject(new RuntimeException('The file [missing-file] does not exist.'));

        Env::writeVariables([
            'APP_VIBE' => 'chill',
            'DB_HOST' => '127:0:0:1',
        ], 'missing-file');
    }

    public function testGetFromSERVERFirst(): void
    {
        $this->withEnvironmentValue('foo', null, function (): void {
            $_ENV['foo'] = 'From $_ENV';
            $_SERVER['foo'] = 'From $_SERVER';
            $this->assertSame('From $_SERVER', env('foo'));
        });
    }

    public function testRequiredEnvVariableThrowsAnExceptionWhenNotFound(): void
    {
        $this->expectExceptionObject(new RuntimeException('Environment variable [required-does-not-exist] has no value.'));

        Env::getOrFail('required-does-not-exist');
    }

    public function testRequiredEnvReturnsValue(): void
    {
        $this->withEnvironmentValue('required-exists', null, function (): void {
            $_SERVER['required-exists'] = 'some-value';
            $this->assertSame('some-value', Env::getOrFail('required-exists'));
        });
    }

    public function testLiteral(): void
    {
        $this->assertEquals(1, literal(1));
        $this->assertSame('taylor', literal('taylor'));
        $this->assertEquals((object) ['name' => 'Taylor', 'role' => 'Developer'], literal(name: 'Taylor', role: 'Developer'));
    }

    #[DataProvider('providesPregReplaceArrayData')]
    public function testPregReplaceArray(string $pattern, array $replacements, string $subject, string $expectedOutput): void
    {
        $this->assertSame(
            $expectedOutput,
            preg_replace_array($pattern, $replacements, $subject)
        );
    }

    /**
     * Provide replacement arrays and expected substitutions.
     */
    public static function providesPregReplaceArrayData(): array
    {
        $pointerArray = ['Taylor', 'Otwell'];

        next($pointerArray);

        return [
            ['/:[a-z_]+/', ['8:30', '9:00'], 'The event will take place between :start and :end', 'The event will take place between 8:30 and 9:00'],
            ['/%s/', ['Taylor'], 'Hi, %s', 'Hi, Taylor'],
            ['/%s/', ['Taylor', 'Otwell'], 'Hi, %s %s', 'Hi, Taylor Otwell'],
            ['/%s/', [], 'Hi, %s %s', 'Hi,  '],
            ['/%s/', ['a', 'b', 'c'], 'Hi', 'Hi'],
            ['//', [], '', ''],
            ['/%s/', ['a'], '', ''],
            // non-sequential numeric keys → should still consume in natural order
            ['/%s/', [2 => 'A', 10 => 'B'], '%s %s', 'A B'],
            // associative keys → order should be insertion order, not keys/pointer
            ['/%s/', ['first' => 'A', 'second' => 'B'], '%s %s', 'A B'],
            // values that are "falsy" but must not be treated as empty by mistake, false->'' , null->''
            ['/%s/', ['0', 0, false, null], '%s|%s|%s|%s', '0|0||'],
            // The internal pointer of this array is not at the beginning
            ['/%s/', $pointerArray, 'Hi, %s %s', 'Hi, Taylor Otwell'],
        ];
    }

    public function testLazy(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(SupportLazyClass::class, function (SupportLazyClass $instance): void {
            $instance->__construct('foo', 'bar');
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testLazyCanAcceptShortClosure(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(SupportLazyClass::class, fn (SupportLazyClass $instance): null => $instance->__construct('foo', 'bar'));

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testLazyThrowsExceptionWhenConstructorIsNotCalled(): void
    {
        $instance = lazy(SupportLazyClass::class, function (SupportLazyClass $instance): void {
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);

        $this->expectException(Error::class);
        $this->expectExceptionMessageIsOrContains('Typed property Hypervel\Tests\Support\SupportLazyClass::$first must not be accessed before initialization');

        $instance->first;
    }

    public function testLazyCanAcceptHashForProperties(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(SupportLazyClass::class, fn (SupportLazyClass $instance): array => [
            'second' => 'bar',
            'first' => 'foo',
        ]);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testLazyCanAcceptListForProperties(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(SupportLazyClass::class, fn (SupportLazyClass $instance): array => ['foo', 'bar']);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testLazyCanAcceptSingleValueForConstructor(): void
    {
        SupportLazyClassWithArrayParameter::$constructorCalled = false;

        $instance = lazy(SupportLazyClassWithArrayParameter::class, fn (SupportLazyClassWithArrayParameter $instance): array => [['foo']]);

        $this->assertFalse(SupportLazyClassWithArrayParameter::$constructorCalled);
        $this->assertSame(['foo'], $instance->first);
        $this->assertTrue(SupportLazyClassWithArrayParameter::$constructorCalled);

        SupportLazyClassWithArrayParameter::$constructorCalled = false;
    }

    public function testLazySupportsPositionAndNamedArguments(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(SupportLazyClass::class, fn (SupportLazyClass $instance): array => ['foo', 'second' => 'bar']);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testLazyThrowsWhenPositionalArgumentsComeAfterNamedArguments(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(SupportLazyClass::class, fn (SupportLazyClass $instance): array => ['second' => 'bar', 'foo']);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessageIsOrContains('Cannot use positional argument after named argument during unpacking');

        $instance->first;
    }

    public function testLazyCanReturnInitializedObject(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(SupportLazyClass::class, function (SupportLazyClass $instance): SupportLazyClass {
            $instance->__construct('foo');

            return $instance;
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertNull($instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testLazyMustInitilizeObject(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(SupportLazyClass::class, function (SupportLazyClass $instance): SupportLazyClass {
            return new SupportLazyClass('foo');
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessageIsOrContains('Typed property Hypervel\Tests\Support\SupportLazyClass::$first must not be accessed before initialization');

        $instance->first;
    }

    public function testLazyCanEagerlySetProperties(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(SupportLazyClass::class, fn (): array => ['foo', 'bar'], eager: ['eager' => 'baz']);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('baz', $instance->eager);
        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('baz', $instance->eager);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testClosureOnlyLazy(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(function (SupportLazyClass $instance): void {
            $instance->__construct('foo', 'bar');
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testClosureOnlyLazyCanAcceptShortClosure(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(fn (SupportLazyClass $instance): null => $instance->__construct('foo', 'bar'));

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testClosureOnlyLazyThrowsExceptionWhenConstructorIsNotCalled(): void
    {
        $instance = lazy(function (SupportLazyClass $instance): void {
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);

        $this->expectException(Error::class);
        $this->expectExceptionMessageIsOrContains('Typed property Hypervel\Tests\Support\SupportLazyClass::$first must not be accessed before initialization');

        $instance->first;
    }

    public function testClosureOnlyLazyThrowsWhenNotClassSpecifiedInClosure(): void
    {
        $this->expectExceptionObject(new RuntimeException('The first parameter of the given Closure is missing a type hint.'));

        lazy(function ($instance): void {
        });
    }

    public function testClosureOnlyLazyCanAcceptHashForProperties(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(fn (SupportLazyClass $instance): array => [
            'second' => 'bar',
            'first' => 'foo',
        ]);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testClosureOnlyLazyCanAcceptListForProperties(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(fn (SupportLazyClass $instance): array => ['foo', 'bar']);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testClousureOnlyLazyCanAcceptSingleValueForConstructor(): void
    {
        SupportLazyClassWithArrayParameter::$constructorCalled = false;

        $instance = lazy(fn (SupportLazyClassWithArrayParameter $instance): array => [['foo']]);

        $this->assertFalse(SupportLazyClassWithArrayParameter::$constructorCalled);
        $this->assertSame(['foo'], $instance->first);
        $this->assertTrue(SupportLazyClassWithArrayParameter::$constructorCalled);

        SupportLazyClassWithArrayParameter::$constructorCalled = false;
    }

    public function testClosureOnlyLazySupportsPositionAndNamedArguments(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(fn (SupportLazyClass $instance): array => ['foo', 'second' => 'bar']);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testClosureOnlyLazyThrowsWhenPositionalArgumentsComeAfterNamedArguments(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(fn (SupportLazyClass $instance): array => ['second' => 'bar', 'foo']);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessageIsOrContains('Cannot use positional argument after named argument during unpacking');

        $instance->first;
    }

    public function testClosureOnlyLazyCanReturnInitializedObject(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(function (SupportLazyClass $instance): SupportLazyClass {
            $instance->__construct('foo');

            return $instance;
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertNull($instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testClosureOnlyLazyMustInitilizeObject(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = lazy(function (SupportLazyClass $instance): SupportLazyClass {
            return new SupportLazyClass('foo');
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessageIsOrContains('Typed property Hypervel\Tests\Support\SupportLazyClass::$first must not be accessed before initialization');

        $instance->first;
    }

    public function testProxy(): void
    {
        SupportLazyClass::$constructorCalled = false;
        $factory = fn (): SupportLazyClass => new SupportLazyClass('foo', 'bar');

        $instance = proxy(SupportLazyClass::class, fn (SupportLazyClass $proxy): SupportLazyClass => $factory());

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testProxyCanEagerlySetProperties(): void
    {
        SupportLazyClass::$constructorCalled = false;
        $factory = fn (): SupportLazyClass => new SupportLazyClass('foo', 'bar');

        $instance = proxy(SupportLazyClass::class, fn (): SupportLazyClass => $factory(), eager: ['eager' => 'baz']);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('baz', $instance->eager);
        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertFalse(isset($instance->eager));

        SupportLazyClass::$constructorCalled = false;
    }

    public function testProxyCanEagerlySetPropertiesAndThenAlsoSetThemOnActualObject(): void
    {
        SupportLazyClass::$constructorCalled = false;
        $factory = fn (): SupportLazyClass => new SupportLazyClass('foo', 'bar');

        $instance = proxy(SupportLazyClass::class, function (SupportLazyClass $proxy, array $eager) use ($factory): SupportLazyClass {
            $instance = $factory();

            foreach ($eager as $property => $value) {
                $instance->{$property} = $value;
            }

            return $instance;
        }, eager: ['eager' => 'baz']);

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('baz', $instance->eager);
        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('baz', $instance->eager);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testProxyCanAcceptShortClosure(): void
    {
        SupportLazyClass::$constructorCalled = false;
        $factory = fn (): SupportLazyClass => new SupportLazyClass('foo', 'bar');

        $instance = proxy(SupportLazyClass::class, fn (SupportLazyClass $proxy): SupportLazyClass => $factory());

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testProxyThrowsExceptionWhenObjectIsNotReturned(): void
    {
        $instance = proxy(SupportLazyClass::class, function (SupportLazyClass $proxy): void {
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);

        $this->expectException(Error::class);
        $this->expectExceptionMessageIsOrContains('Lazy proxy factory must return an instance of a class compatible with Hypervel\Tests\Support\SupportLazyClass, null returned');

        $instance->first;
    }

    public function testProxyMustNotInitilizeProxy(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = proxy(SupportLazyClass::class, function (SupportLazyClass $proxy): SupportLazyClass {
            $proxy->__construct('foo');

            return $proxy;
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessageIsOrContains('Lazy proxy factory must return a non-lazy object');

        $instance->first;
    }

    public function testClosureOnlyProxy(): void
    {
        SupportLazyClass::$constructorCalled = false;
        $factory = fn (): SupportLazyClass => new SupportLazyClass('foo', 'bar');

        $instance = proxy(function (SupportLazyClass $proxy) use ($factory) {
            return $factory();
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testClosureOnlyProxyCanAcceptShortClosure(): void
    {
        SupportLazyClass::$constructorCalled = false;
        $factory = fn (): SupportLazyClass => new SupportLazyClass('foo', 'bar');

        $instance = proxy(fn (SupportLazyClass $proxy) => $factory());

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testClosureOnlyProxyThrowsExceptionWhenObjectIsNotReturned(): void
    {
        $instance = proxy(function (SupportLazyClass $proxy): void {
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);

        $this->expectException(Error::class);
        $this->expectExceptionMessageIsOrContains('Lazy proxy factory must return an instance of a class compatible with Hypervel\Tests\Support\SupportLazyClass, null returned');

        $instance->first;
    }

    public function testClosureOnlyProxyThrowsWhenNotClassSpecifiedInClosure(): void
    {
        $this->expectExceptionObject(new RuntimeException('The first parameter of the given Closure is missing a type hint.'));

        proxy(function ($proxy): void {
        });
    }

    public function testClosureOnlyProxyMustNotInitilizeProxy(): void
    {
        SupportLazyClass::$constructorCalled = false;

        $instance = proxy(function (SupportLazyClass $proxy) {
            $proxy->__construct('foo');

            return $proxy;
        });

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessageIsOrContains('Lazy proxy factory must return a non-lazy object');

        $instance->first;
    }

    public function testProxyCanUseClosureReturnTypeForClassDetection(): void
    {
        SupportLazyClass::$constructorCalled = false;
        $factory = fn (): SupportLazyClass => new SupportLazyClass('foo', 'bar');

        $instance = proxy(fn (): SupportLazyClass => $factory());

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);

        SupportLazyClass::$constructorCalled = false;
    }

    public function testProxyFallsBackToParameterTypeForRelativeReturnType(): void
    {
        $instance = proxy(SupportParentReturnFactory::make(...));

        $this->assertFalse(SupportLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(SupportLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }
}

trait SupportTestTraitOne
{
}

trait SupportTestTraitTwo
{
    use SupportTestTraitOne;
}

class SupportTestClassOne
{
    use SupportTestTraitTwo;
}

class SupportTestClassTwo extends SupportTestClassOne
{
}

trait SupportTestTraitThree
{
}

class SupportTestClassThree extends SupportTestClassTwo
{
    use SupportTestTraitThree;
}

trait SupportTestTraitArrayAccess
{
    /**
     * Create an array access fixture.
     */
    public function __construct(protected array $items = [])
    {
    }

    /**
     * Determine whether an offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset ?? '', $this->items);
    }

    /**
     * Get the value at an offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    /**
     * Set the value at an offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->items[$offset] = $value;
    }

    /**
     * Remove the value at an offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
}

trait SupportTestTraitArrayIterable
{
    /**
     * Create an iterable fixture.
     */
    public function __construct(protected array $items = [])
    {
    }

    /**
     * Get an iterator over the items.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}

class SupportTestArrayAccess implements ArrayAccess
{
    use SupportTestTraitArrayAccess;
}

class SupportTestArrayIterable implements IteratorAggregate
{
    use SupportTestTraitArrayIterable;
}

class SupportTestArrayAccessIterable implements ArrayAccess, IteratorAggregate
{
    use SupportTestTraitArrayAccess, SupportTestTraitArrayIterable {
        SupportTestTraitArrayAccess::__construct insteadof SupportTestTraitArrayIterable;
    }
}

class SupportTestCountable implements Countable
{
    /**
     * Return the empty fixture's item count.
     */
    public function count(): int
    {
        return 0;
    }
}

class SupportLazyClass
{
    public static bool $constructorCalled = false;

    public string $eager;

    /**
     * Initialize the lazy object's properties.
     */
    public function __construct(
        public string $first,
        public ?string $second = null,
    ) {
        self::$constructorCalled = true;
    }
}

class SupportLazyClassWithArrayParameter
{
    public static bool $constructorCalled = false;

    /**
     * Initialize the lazy object's array property.
     */
    public function __construct(
        public array $first,
    ) {
        self::$constructorCalled = true;
    }
}

class SupportParentReturnFactory extends SupportLazyClass
{
    /**
     * Create a parent instance with a relative return type.
     */
    public static function make(SupportLazyClass $proxy): parent
    {
        return new parent('foo', 'bar');
    }
}
