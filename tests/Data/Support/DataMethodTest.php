<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Attribute;
use Hypervel\Container\Attributes\Config;
use Hypervel\Container\Container;
use Hypervel\Data\Enums\CustomCreationMethodType;
use Hypervel\Data\Exceptions\InvalidDataDeclaration;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataMethod;
use Hypervel\Data\Support\DataMethodMatch;
use Hypervel\Data\Support\Factories\DataMethodFactory;
use Hypervel\Data\Support\Factories\DataParameterFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

class DataMethodTest extends TestCase
{
    /**
     * Test constructor and named creation metadata.
     */
    public function testMethodMetadataPreservesDeclarationDetails(): void
    {
        $constructor = $this->method('__construct');

        $this->assertSame('__construct', $constructor->name);
        $this->assertCount(2, $constructor->parameters);
        $this->assertTrue($constructor->isPublic);
        $this->assertFalse($constructor->isStatic);
        $this->assertSame(CustomCreationMethodType::None, $constructor->customCreationMethodType);
        $this->assertNull($constructor->returnType);
        $this->assertTrue($constructor->parameters[0]->isPromoted);
        $this->assertFalse($constructor->parameters[1]->isPromoted);

        $from = $this->method('fromValues');

        $this->assertSame(CustomCreationMethodType::Object, $from->customCreationMethodType);
        $this->assertTrue($from->isStatic);
        $this->assertTrue($from->returns(DataMethodFixture::class));
        $this->assertSame('fromValues', $from->reflection->name);

        $collect = $this->method('collectValues');

        $this->assertSame(CustomCreationMethodType::Collection, $collect->customCreationMethodType);
        $this->assertTrue($collect->returnType?->isNullable);
        $this->assertTrue($collect->returns('array'));

        $this->assertSame(
            CustomCreationMethodType::None,
            $this->method('collectUntyped')->customCreationMethodType,
        );
    }

    /**
     * Test positional and named payload matching.
     */
    public function testPayloadMatchingUsesTypesDefaultsAndContainerDependencies(): void
    {
        $method = $this->method('fromValues');
        $context = $this->context();

        $match = $method->matchPayloads($context, 'value', 42);

        $this->assertSame(['value' => 'value', 'number' => 42], $match?->arguments);
        $this->assertTrue($match?->requiresContainerCall);

        $dependency = new DataMethodDependency;
        $match = $method->matchPayloads($context, 'value', 42, $dependency);

        $this->assertSame(
            ['value' => 'value', 'number' => 42, 'dependency' => $dependency],
            $match?->arguments,
        );
        $this->assertFalse($match?->requiresContainerCall);

        $match = $method->matchPayloads($context, ...['number' => 42, 'value' => 'value']);

        $this->assertSame(['value' => 'value', 'number' => 42], $match?->arguments);
        $this->assertNull($method->matchPayloads($context, 42, 'value'));
        $this->assertNull($method->matchPayloads($context, 'value'));
        $this->assertNull($method->matchPayloads($context, 'value', 42, $dependency, 'extra'));
        $this->assertNull($method->matchPayloads($context, ...['value' => 'value', 'unknown' => 42]));

        $dependencyFirst = $this->method('fromDependencyFirst')->matchPayloads($context, 'value');
        $interleaved = $this->method('fromInterleaved')->matchPayloads($context, 'value', 42);

        $this->assertSame(['value' => 'value'], $dependencyFirst?->arguments);
        $this->assertTrue($dependencyFirst?->requiresContainerCall);
        $this->assertSame(['value' => 'value', 'number' => 42], $interleaved?->arguments);
        $this->assertTrue($interleaved?->requiresContainerCall);
    }

    /**
     * Test creation context placement and container identity.
     */
    public function testCreationContextIsPlacedDuringTheParameterWalk(): void
    {
        $context = $this->context();

        $first = $this->method('fromCreationContextFirst')->matchPayloads($context, 'value');
        $middle = $this->method('fromCreationContextMiddle')->matchPayloads($context, 'value', 42);
        $trailing = $this->method('fromCreationContextTrailing')->matchPayloads($context, 'value');
        $two = $this->method('fromTwoCreationContexts')->matchPayloads($context, 'value');

        $this->assertSame(['context' => $context, 'value' => 'value'], $first?->arguments);
        $this->assertSame(
            ['value' => 'value', 'context' => $context, 'number' => 42],
            $middle?->arguments,
        );
        $this->assertSame(['value' => 'value', 'context' => $context], $trailing?->arguments);
        $this->assertSame(
            ['first' => $context, 'value' => 'value', 'second' => $context],
            $two?->arguments,
        );

        $variadic = $this->method('fromCreationContextVariadic')->matchPayloads($context, 'value', 1, 2);

        $this->assertSame(['value', $context, 1, 2], $variadic?->arguments);
        $this->assertFalse($variadic?->requiresContainerCall);

        $containerMatch = $this->method(
            'fromCreationContextDependency',
            DataMethodInvocationFixture::class,
        )->matchPayloads($context, 'value');

        $this->assertTrue($containerMatch?->requiresContainerCall);

        $result = $this->invoke(
            DataMethodInvocationFixture::class,
            'fromCreationContextDependency',
            $containerMatch,
            new Container,
        );

        $this->assertSame($context, $result[0]);
        $this->assertInstanceOf(DataMethodDependency::class, $result[1]);
        $this->assertSame('value', $result[2]);
    }

    /**
     * Test contextual, defaulted, union, and intersection parameters.
     */
    public function testOnlySingleNamedClassesAreImplicitlyInjectable(): void
    {
        $context = $this->context();
        $contextual = $this->method('fromContext')->matchPayloads($context, 'value');

        $this->assertSame(['value' => 'value'], $contextual?->arguments);
        $this->assertTrue($contextual?->requiresContainerCall);
        $this->assertNull($this->method('fromContext')->matchPayloads($context, 42));

        $this->assertSame([], $this->method('fromDefault')->matchPayloads($context)?->arguments);
        $this->assertSame(
            ['value' => 'value'],
            $this->method('fromDefault')->matchPayloads($context, 'value')?->arguments,
        );

        $union = $this->method('fromUnion');
        $intersection = $this->method('fromIntersection');

        $this->assertNull($union->matchPayloads($context));
        $this->assertSame(['value' => 'value'], $union->matchPayloads($context, 'value')?->arguments);
        $this->assertNull($intersection->matchPayloads($context));

        $intersectionValue = new DataMethodIntersectionDependency;

        $this->assertSame(
            ['value' => $intersectionValue],
            $intersection->matchPayloads($context, $intersectionValue)?->arguments,
        );

        $contextUnion = $this->method('fromCreationContextUnion');

        $this->assertNull($contextUnion->matchPayloads($context));
        $this->assertSame(
            ['value' => 'value'],
            $contextUnion->matchPayloads($context, 'value')?->arguments,
        );
    }

    /**
     * Test direct and container variadic argument shapes.
     */
    public function testVariadicMatchesUseRepresentableInvocationShapes(): void
    {
        $context = $this->context();

        $this->assertSame([], $this->method('fromVariadic')->matchPayloads($context)?->arguments);
        $this->assertSame(
            ['first', 'second'],
            $this->method('fromVariadic')->matchPayloads($context, 'first', 'second')?->arguments,
        );
        $this->assertNull($this->method('fromVariadic')->matchPayloads($context, 'first', 2));

        $prefixed = $this->method(
            'fromPrefixedVariadic',
            DataMethodInvocationFixture::class,
        )->matchPayloads($context, 'prefix', 'first', 'second');

        $this->assertSame(['prefix', 'first', 'second'], $prefixed?->arguments);
        $this->assertFalse($prefixed?->requiresContainerCall);

        $defaulted = $this->method(
            'fromDefaultVariadic',
            DataMethodInvocationFixture::class,
        )->matchPayloads($context, 'first', 'second');

        $this->assertSame(['first', 'second'], $defaulted?->arguments);
        $this->assertTrue($defaulted?->requiresContainerCall);
        $this->assertSame(
            [5, 'first', 'second'],
            $this->invoke(
                DataMethodInvocationFixture::class,
                'fromDefaultVariadic',
                $defaulted,
                new Container,
            ),
        );

        $dependency = $this->method(
            'fromDependencyVariadic',
            DataMethodInvocationFixture::class,
        )->matchPayloads($context, 'first', 'second');

        $this->assertSame(['first', 'second'], $dependency?->arguments);
        $this->assertTrue($dependency?->requiresContainerCall);

        $dependencyResult = $this->invoke(
            DataMethodInvocationFixture::class,
            'fromDependencyVariadic',
            $dependency,
            new Container,
        );

        $this->assertInstanceOf(DataMethodDependency::class, $dependencyResult[0]);
        $this->assertSame(['first', 'second'], array_slice($dependencyResult, 1));

        $named = $this->method(
            'fromPrefixedVariadic',
            DataMethodInvocationFixture::class,
        )->matchPayloads($context, ...[
            'prefix' => 'prefix',
            'first' => 'first',
            'second' => 'second',
        ]);

        $this->assertSame(['prefix', 'first', 'second'], $named?->arguments);
    }

    /**
     * Test class variadics preserve caller payloads across Container dispatch.
     */
    public function testClassVariadicsUseTheClassKeyOnContainerDispatch(): void
    {
        $context = $this->context();
        $first = new DataMethodModel;
        $second = new DataMethodModel;
        $container = new Container;
        $attributeCallbacks = 0;

        $container->afterResolvingAttribute(
            DataMethodMarker::class,
            function () use (&$attributeCallbacks): void {
                ++$attributeCallbacks;
            },
        );

        $method = $this->method('fromAttributedModels', DataMethodInvocationFixture::class);
        $match = $method->matchPayloads($context, 'prefix', $first, $second);

        $this->assertSame(
            ['prefix' => 'prefix', DataMethodModel::class => $first, 0 => $second],
            $match?->arguments,
        );
        $this->assertTrue($match?->requiresContainerCall);

        $result = $this->invoke(
            DataMethodInvocationFixture::class,
            'fromAttributedModels',
            $match,
            $container,
        );

        $this->assertSame(['prefix', $first, $second], $result);
        $this->assertSame(1, $attributeCallbacks);

        $sameClass = $this->method('fromModels', DataMethodInvocationFixture::class)
            ->matchPayloads($context, $first, $second);

        $this->assertSame([$first, $second], $sameClass?->arguments);
        $this->assertFalse($sameClass?->requiresContainerCall);
        $this->assertSame(
            [$first, $second],
            $this->invoke(DataMethodInvocationFixture::class, 'fromModels', $sameClass, $container),
        );

        $zeroPayload = $method->matchPayloads($context, 'prefix');
        $zeroResult = $this->invoke(
            DataMethodInvocationFixture::class,
            'fromAttributedModels',
            $zeroPayload,
            $container,
        );

        $this->assertSame('prefix', $zeroResult[0]);
        $this->assertInstanceOf(DataMethodModel::class, $zeroResult[1]);
        $this->assertCount(2, $zeroResult);
        $this->assertSame(2, $attributeCallbacks);
    }

    /**
     * Test variadic creation contexts are rejected as invalid factories.
     */
    public function testVariadicCreationContextIsRejectedDuringMetadataBuild(): void
    {
        $this->expectException(InvalidDataDeclaration::class);
        $this->expectExceptionMessage(
            'Data factory [Hypervel\Tests\Data\Support\DataMethodInvalidFixture::fromContexts] '
            . 'cannot declare variadic CreationContext parameter [$contexts]. '
            . 'Declare a single CreationContext parameter instead.',
        );

        $this->method('fromContexts', DataMethodInvalidFixture::class);
    }

    /**
     * Build metadata for one fixture method.
     */
    protected function method(string $name, string $className = DataMethodFixture::class): DataMethod
    {
        $class = new ReflectionClass($className);
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver);
        $factory = new DataMethodFactory(
            new DataParameterFactory($typeFactory),
            $typeFactory,
        );

        return $factory->build(new ReflectionMethod($className, $name), $class);
    }

    /**
     * Create an operation context for matching.
     */
    protected function context(): CreationContext
    {
        return new CreationContext(DataMethodFixture::class);
    }

    /**
     * Invoke a matched fixture method through its selected path.
     */
    protected function invoke(
        string $className,
        string $methodName,
        ?DataMethodMatch $match,
        Container $container,
    ): mixed {
        $this->assertNotNull($match);

        return $match->requiresContainerCall
            ? $container->call($className::$methodName(...), $match->arguments)
            : $className::$methodName(...$match->arguments);
    }
}

class DataMethodDependency
{
}

class DataMethodFixture
{
    /**
     * Create a new fixture.
     */
    public function __construct(
        public string $promoted = 'hello',
        string $plain = 'world',
    ) {
    }

    /**
     * Create a fixture from scalar values.
     */
    public static function fromValues(
        string $value,
        int $number,
        DataMethodDependency $dependency,
    ): self {
        return new self($value);
    }

    /**
     * Create a fixture from a contextual value.
     */
    public static function fromContext(
        #[Config('app.name')]
        string $context,
        string $value,
    ): self {
        return new self($value);
    }

    /**
     * Create a fixture with a leading dependency.
     */
    public static function fromDependencyFirst(
        DataMethodDependency $dependency,
        string $value,
    ): self {
        return new self($value);
    }

    /**
     * Create a fixture with an interleaved dependency.
     */
    public static function fromInterleaved(
        string $value,
        DataMethodDependency $dependency,
        int $number,
    ): self {
        return new self($value);
    }

    /**
     * Create a fixture with a leading creation context.
     */
    public static function fromCreationContextFirst(
        CreationContext $context,
        string $value,
    ): self {
        return new self($value);
    }

    /**
     * Create a fixture with an interleaved creation context.
     */
    public static function fromCreationContextMiddle(
        string $value,
        CreationContext $context,
        int $number,
    ): self {
        return new self($value);
    }

    /**
     * Create a fixture with a trailing creation context.
     */
    public static function fromCreationContextTrailing(
        string $value,
        CreationContext $context,
    ): self {
        return new self($value);
    }

    /**
     * Create a fixture with two creation contexts.
     */
    public static function fromTwoCreationContexts(
        CreationContext $first,
        string $value,
        CreationContext $second,
    ): self {
        return new self($value);
    }

    /**
     * Create a fixture with a creation context before variadic values.
     */
    public static function fromCreationContextVariadic(
        string $value,
        CreationContext $context,
        int ...$numbers,
    ): self {
        return new self($value);
    }

    /**
     * Create a fixture from a class or scalar value.
     */
    public static function fromUnion(DataMethodDependency|string $value): self
    {
        return new self(is_string($value) ? $value : 'dependency');
    }

    /**
     * Create a fixture from an intersection value.
     */
    public static function fromIntersection(
        DataMethodFirstContract&DataMethodSecondContract $value,
    ): self {
        return new self('intersection');
    }

    /**
     * Create a fixture from a creation context union.
     */
    public static function fromCreationContextUnion(CreationContext|string $value): self
    {
        return new self(is_string($value) ? $value : 'context');
    }

    /**
     * Create a fixture from an optional value.
     */
    public static function fromDefault(string $value = 'default'): self
    {
        return new self($value);
    }

    /**
     * Create a fixture from variadic values.
     */
    public static function fromVariadic(string ...$values): self
    {
        return new self($values[0] ?? 'default');
    }

    /**
     * Collect fixture values.
     *
     * @return null|array<int, string>
     */
    public static function collectValues(array $values): ?array
    {
        return $values;
    }

    /**
     * Collect fixture values without a declared return type.
     */
    public static function collectUntyped(array $values)
    {
        return $values;
    }
}

interface DataMethodFirstContract
{
}

interface DataMethodSecondContract
{
}

class DataMethodIntersectionDependency implements DataMethodFirstContract, DataMethodSecondContract
{
}

class DataMethodModel
{
}

#[Attribute(Attribute::TARGET_PARAMETER)]
class DataMethodMarker
{
}

class DataMethodInvocationFixture
{
    /**
     * Return a context with an injected dependency.
     */
    public static function fromCreationContextDependency(
        CreationContext $context,
        DataMethodDependency $dependency,
        string $value,
    ): array {
        return [$context, $dependency, $value];
    }

    /**
     * Return prefixed variadic values.
     */
    public static function fromPrefixedVariadic(string $prefix, string ...$values): array
    {
        return [$prefix, ...$values];
    }

    /**
     * Return variadic values after a default.
     */
    public static function fromDefaultVariadic(int $count = 5, string ...$values): array
    {
        return [$count, ...$values];
    }

    /**
     * Return an injected dependency and variadic values.
     */
    public static function fromDependencyVariadic(
        DataMethodDependency $dependency,
        string ...$values,
    ): array {
        return [$dependency, ...$values];
    }

    /**
     * Return attributed prefix and model payloads.
     *
     * @return array{string, DataMethodModel, DataMethodModel...}
     */
    public static function fromAttributedModels(
        #[DataMethodMarker]
        string $prefix,
        DataMethodModel ...$models,
    ): array {
        return [$prefix, ...$models];
    }

    /**
     * Return the first model and remaining model payloads.
     *
     * @return non-empty-list<DataMethodModel>
     */
    public static function fromModels(
        DataMethodModel $first,
        DataMethodModel ...$models,
    ): array {
        return [$first, ...$models];
    }
}

class DataMethodInvalidFixture
{
    /**
     * Declare an invalid variadic creation context.
     */
    public static function fromContexts(CreationContext ...$contexts): self
    {
        return new self;
    }
}
