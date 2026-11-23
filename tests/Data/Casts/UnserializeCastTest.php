<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Casts;

use __PHP_Incomplete_Class;
use ErrorException;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Casts\Uncastable;
use Hypervel\Data\Casts\UnserializeCast;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use LogicException;
use ReflectionClass;
use Swoole\Coroutine\CanceledException;
use TypeError;

class UnserializeCastTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testWillUnserializeAnObject(): void
    {
        $value = serialize(DummyBackedEnum::FOO);

        $cast = new UnserializeCast;

        $this->assertEquals(
            DummyBackedEnum::FOO,
            $cast->cast($this->property('enum'), $value, [], $this->context()),
        );
    }

    public function testWillThrowAnExceptionWhenTheUnserializationFails(): void
    {
        $cast = new UnserializeCast;

        $this->expectException(ErrorException::class);

        $cast->cast($this->property('enum'), 'foo', [], $this->context());
    }

    public function testCanFailSilently(): void
    {
        $cast = new UnserializeCast(true);

        $this->assertInstanceOf(
            Uncastable::class,
            $cast->cast($this->property('enum'), 'foo', [], $this->context()),
        );
    }

    public function testCanRestrictTheClassesWhichCanBeUnserialized(): void
    {
        $value = serialize(new SimpleData('Hello'));

        $context = $this->context();

        $this->assertEquals(
            new SimpleData('Hello'),
            (new UnserializeCast(allowedClasses: [SimpleData::class]))->cast($this->property('data'), $value, [], $context),
        );

        $this->assertInstanceOf(
            __PHP_Incomplete_Class::class,
            (new UnserializeCast(allowedClasses: []))->cast($this->property('data'), $value, [], $context),
        );

        $this->assertInstanceOf(
            __PHP_Incomplete_Class::class,
            (new UnserializeCast(allowedClasses: false))->cast($this->property('data'), $value, [], $context),
        );
    }

    public function testRestoresNoObjectsUnlessTheirClassesAreAllowed(): void
    {
        $value = serialize(new SimpleData('Hello'));

        $this->assertInstanceOf(
            __PHP_Incomplete_Class::class,
            (new UnserializeCast)->cast($this->property('data'), $value, [], $this->context()),
        );

        $this->assertEquals(
            new SimpleData('Hello'),
            (new UnserializeCast(allowedClasses: true))->cast($this->property('data'), $value, [], $this->context()),
        );
    }

    public function testADataObjectRestoresOnlyTheAllowedClasses(): void
    {
        $value = serialize(new SimpleData('Hello'));

        $this->assertEquals(
            new SimpleData('Hello'),
            UnserializeCastAllowedData::from(['data' => $value])->data,
        );

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('__PHP_Incomplete_Class given');

        UnserializeCastRestrictedData::from(['data' => $value]);
    }

    public function testCancellationInARestoreHookIsNotTreatedAsAFailedCast(): void
    {
        $cast = new UnserializeCast(failSilently: true, allowedClasses: [UnserializeCastCanceledHook::class]);

        try {
            $cast->cast($this->property('data'), serialize(new UnserializeCastCanceledHook), [], $this->context());
            $this->fail('Cancellation was treated as a failed cast.');
        } catch (CanceledException $exception) {
            $this->assertSame('Canceled while restoring.', $exception->getMessage());
        }

        try {
            $cast->cast($this->property('data'), serialize(new UnserializeCastCanceledHook(cleanupFails: true)), [], $this->context());
            $this->fail('Cancellation was treated as a failed cast after its cleanup failed.');
        } catch (LogicException $exception) {
            $this->assertInstanceOf(CanceledException::class, $exception->getPrevious());
        }
    }

    /**
     * Build one property definition.
     */
    protected function property(string $name): DataProperty
    {
        $defaults = require __DIR__ . '/../../../src/data/config/data.php';
        $config = new DataConfig(new Repository(['data' => $defaults]));
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver, new DataIterableAnnotationReader);
        $reflectionClass = new ReflectionClass(UnserializeCastDataFixture::class);
        $reflectionProperty = $reflectionClass->getProperty($name);

        return (new DataPropertyFactory(
            $typeFactory,
            $config,
            new NameMapperResolver(new Container),
        ))->build(
            $reflectionProperty,
            $reflectionClass,
            classDefinedDataIterableAnnotations: (new DataIterableAnnotationReader)->getForProperty(
                $reflectionProperty,
            ),
        );
    }

    /**
     * Create the creation context for one cast.
     */
    protected function context(): CreationContext
    {
        return new CreationContext(SimpleData::class);
    }
}

class UnserializeCastDataFixture
{
    public DummyBackedEnum $enum;

    public SimpleData $data;
}

class UnserializeCastAllowedData extends Data
{
    /**
     * Create a data object that restores its serialized data object.
     */
    public function __construct(
        #[WithCast(UnserializeCast::class, allowedClasses: [SimpleData::class])]
        public SimpleData $data,
    ) {
    }
}

class UnserializeCastRestrictedData extends Data
{
    /**
     * Create a data object that restores no serialized objects.
     */
    public function __construct(
        #[WithCast(UnserializeCast::class)]
        public SimpleData $data,
    ) {
    }
}

class UnserializeCastCanceledHook
{
    /**
     * Create a value whose restore hook is canceled.
     */
    public function __construct(
        public bool $cleanupFails = false,
    ) {
    }

    /**
     * Fail as a restore hook does when its coroutine is canceled while it waits.
     */
    public function __unserialize(array $data): void
    {
        try {
            throw new CanceledException('Canceled while restoring.');
        } finally {
            if ($data['cleanupFails']) {
                throw new LogicException('Cleanup failed.');
            }
        }
    }
}
