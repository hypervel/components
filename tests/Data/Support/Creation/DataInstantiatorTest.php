<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Creation;

use Attribute;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Container\ContextualAttribute;
use Hypervel\Data\Data;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\Creation\DataInstantiator;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Factories\DataClassFactory;
use Hypervel\Data\Support\Factories\DataMethodFactory;
use Hypervel\Data\Support\Factories\DataParameterFactory;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Tests\TestCase;
use ReflectionClass;

class DataInstantiatorTest extends TestCase
{
    /**
     * Test direct and ordinary instantiation preserve constructor behavior.
     */
    public function testDirectInstantiationMatchesTheOrdinaryConstructorPath(): void
    {
        $metadata = $this->metadata(InstantiatorDirectDataFixture::class);
        $instantiator = new DataInstantiator(new Container);

        $this->assertTrue($metadata->directConstructorInstantiation);
        $this->assertEquals(
            $instantiator->instantiate($metadata, ['name' => 'taylor']),
            $instantiator->instantiateDirect($metadata, ['name' => 'taylor']),
        );
        $this->assertSame(
            'TAYLOR',
            $instantiator->instantiateDirect($metadata, ['name' => 'taylor'])->name,
        );
    }

    /**
     * Test constructor-bound values are not overwritten after construction.
     */
    public function testPreservesConstructorNormalizationAndAssignsOnlyUnboundProperties(): void
    {
        $data = (new DataInstantiator(new Container))->instantiate(
            $this->metadata(InstantiatorDataFixture::class),
            ['name' => 'taylor', 'note' => 'assigned'],
        );

        $this->assertSame('TAYLOR', $data->name);
        $this->assertSame('assigned', $data->note);
    }

    /**
     * Test constructor and property defaults remain owned by PHP.
     */
    public function testOmitsMissingDefaultedValues(): void
    {
        $data = (new DataInstantiator(new Container))->instantiate(
            $this->metadata(InstantiatorDefaultDataFixture::class),
            [],
        );

        $this->assertInstanceOf(InstantiatorDefaultValue::class, $data->value);
        $this->assertSame('property-default', $data->note);
    }

    /**
     * Test contextual constructor parameters use the container build path.
     */
    public function testResolvesContextualConstructorParameters(): void
    {
        $data = (new DataInstantiator(new Container))->instantiate(
            $this->metadata(InstantiatorContextualDataFixture::class),
            [],
        );

        $this->assertSame(42, $data->serverId);
    }

    /**
     * Test missing constructor values produce a focused creation failure.
     */
    public function testThrowsForMissingConstructorValues(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('Parameters missing: name');

        (new DataInstantiator(new Container))->instantiate(
            $this->metadata(InstantiatorDataFixture::class),
            [],
        );
    }

    /**
     * Test contextual parameters are excluded from missing payload diagnostics.
     */
    public function testMissingConstructorDiagnosticsExcludeContextualParameters(): void
    {
        try {
            (new DataInstantiator(new Container))->instantiate(
                $this->metadata(InstantiatorContextualMissingDataFixture::class),
                [],
            );
            $this->fail('Expected the missing constructor value to be rejected.');
        } catch (CannotCreateData $exception) {
            $this->assertStringContainsString('Parameters missing: name.', $exception->getMessage());
            $this->assertStringNotContainsString('serverId', $exception->getMessage());
        }
    }

    /**
     * Test missing unbound values produce a focused creation failure.
     */
    public function testThrowsForMissingUnboundPropertyValues(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('required property');

        (new DataInstantiator(new Container))->instantiate(
            $this->metadata(InstantiatorUnboundDataFixture::class),
            [],
        );
    }

    /**
     * Test ordinary construction cannot bypass a non-public constructor.
     */
    public function testThrowsForNonPublicOrdinaryConstruction(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('constructor is private');
        $this->expectExceptionMessageMatches('/matching public static from\* method/');

        (new DataInstantiator(new Container))->instantiate(
            $this->metadata(InstantiatorPrivateDataFixture::class),
            ['name' => 'Taylor'],
        );
    }

    /**
     * Test ordinary construction reports a protected constructor accurately.
     */
    public function testThrowsForProtectedOrdinaryConstruction(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessageIsOrContains('constructor is protected');

        (new DataInstantiator(new Container))->instantiate(
            $this->metadata(InstantiatorProtectedDataFixture::class),
            ['name' => 'Taylor'],
        );
    }

    /**
     * Build metadata for a data fixture.
     *
     * @param class-string<Data> $class
     */
    protected function metadata(string $class): DataClass
    {
        $defaults = require __DIR__ . '/../../../../src/data/config/data.php';
        $config = new DataConfig(new Repository(['data' => $defaults]));
        $container = new Container;
        $nameMapperResolver = new NameMapperResolver($container);
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver);
        $parameterFactory = new DataParameterFactory($typeFactory);

        return (new DataClassFactory(
            new DataPropertyFactory($typeFactory, $config, $nameMapperResolver),
            new DataMethodFactory($parameterFactory, $typeFactory),
            $parameterFactory,
            new DataIterableAnnotationReader,
            $nameMapperResolver,
            $config,
        ))->build(new ReflectionClass($class));
    }
}

class InstantiatorDataFixture extends Data
{
    public string $name;

    public string $note = 'property-default';

    /**
     * Create an instantiator fixture.
     */
    public function __construct(string $name)
    {
        $this->name = strtoupper($name);
    }
}

class InstantiatorDirectDataFixture extends Data
{
    /**
     * Create a direct-instantiation fixture.
     */
    public function __construct(public string $name)
    {
        $this->name = strtoupper($name);
    }
}

class InstantiatorDefaultDataFixture extends Data
{
    public readonly InstantiatorDefaultValue $value;

    public string $note = 'property-default';

    /**
     * Create a default fixture.
     */
    public function __construct(InstantiatorDefaultValue $value = new InstantiatorDefaultValue)
    {
        $this->value = $value;
    }
}

class InstantiatorDefaultValue
{
}

class InstantiatorContextualDataFixture extends Data
{
    /**
     * Create a contextual fixture.
     */
    public function __construct(
        #[InstantiatorContextualValue]
        public int $serverId,
    ) {
    }
}

class InstantiatorContextualMissingDataFixture extends Data
{
    /**
     * Create a contextual fixture with a required payload value.
     */
    public function __construct(
        #[InstantiatorContextualValue]
        public int $serverId,
        public string $name,
    ) {
    }
}

class InstantiatorUnboundDataFixture extends Data
{
    public string $required;
}

class InstantiatorPrivateDataFixture extends Data
{
    public readonly string $name;

    /**
     * Create a private-constructor fixture.
     */
    private function __construct(string $name)
    {
        $this->name = $name;
    }
}

class InstantiatorProtectedDataFixture extends Data
{
    public readonly string $name;

    /**
     * Create a protected-constructor fixture.
     */
    protected function __construct(string $name)
    {
        $this->name = $name;
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
class InstantiatorContextualValue implements ContextualAttribute
{
    /**
     * Resolve the server-owned fixture value.
     */
    public static function resolve(self $attribute, ContainerContract $container): int
    {
        return 42;
    }
}
