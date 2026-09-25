<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\ImplicitRouteBindingTest;

use Closure;
use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\InvalidValueException;
use Hypervel\Database\QueryException;
use Hypervel\Http\Request;
use Hypervel\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Hypervel\Routing\ImplicitRouteBinding;
use Hypervel\Routing\Route;
use Hypervel\Tests\Routing\Fixtures\CategoryBackedEnum;
use Hypervel\Tests\Routing\Fixtures\CategoryEnum;
use Hypervel\Tests\Routing\RoutingTestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use WeakMap;

class ImplicitRouteBindingTest extends RoutingTestCase
{
    public function testItDoesNotInspectTheActionWhenTheRouteHasNoParameters(): void
    {
        $route = new EmptyParameterRoute('GET', '/test', fn () => 'ok');
        $route->bind(Request::create('/test'));

        ImplicitRouteBinding::resolveForRoute(Container::getInstance(), $route);

        $this->assertSame(0, $route->signatureParameterCalls);
    }

    public function testItCanResolveTheImplicitBackedEnumRouteBindingsForTheGivenRoute(): void
    {
        $action = ['uses' => function (CategoryBackedEnum $category) {
            return $category->value;
        }];

        $route = new Route('GET', '/test/{category}', $action);
        $route->bind(Request::create('/test/fruits'));

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);

        $this->assertSame('fruits', $route->parameter('category')->value);
    }

    public function testItCanResolveTheImplicitBackedEnumRouteBindingsForTheGivenRouteWithOptionalParameter(): void
    {
        $action = ['uses' => function (?CategoryBackedEnum $category = null) {
            return $category->value;
        }];

        $route = new Route('GET', '/test/{category?}', $action);
        $route->bind(Request::create('/test/fruits'));

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);

        $this->assertSame('fruits', $route->parameter('category')->value);
    }

    public function testItHandlesOptionalImplicitBackedEnumRouteBindingsForTheGivenRouteWithOptionalParameter(): void
    {
        $action = ['uses' => function (?CategoryBackedEnum $category = null) {
            return $category->value;
        }];

        $route = (new Route('GET', '/test/{category?}', $action))->defaults('category', null);
        $route->bind(Request::create('/test'));

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);

        $this->assertNull($route->parameter('category'));
    }

    public function testItDoesNotResolveImplicitNonBackedEnumRouteBindingsForTheGivenRoute(): void
    {
        $action = ['uses' => function (CategoryEnum $category) {
            return $category->value;
        }];

        $route = new Route('GET', '/test/{category}', $action);
        $route->bind(Request::create('/test/fruits'));

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);

        $this->assertIsString($route->parameter('category'));
        $this->assertSame('fruits', $route->parameter('category'));
    }

    public function testImplicitBackedEnumInternalException(): void
    {
        $action = ['uses' => function (CategoryBackedEnum $category): string {
            return $category->value;
        }];

        $route = new Route('GET', '/test/{category}', $action);
        $route->bind(Request::create('/test/cars'));

        $route->prepareForSerialization();

        $container = Container::getInstance();

        $this->expectExceptionObject(new BackedEnumCaseNotFoundException(CategoryBackedEnum::class, 'cars'));

        ImplicitRouteBinding::resolveForRoute($container, $route);
    }

    public function testItCanResolveTheImplicitModelRouteBindingsForTheGivenRoute(): void
    {
        $this->expectNotToPerformAssertions();

        $action = ['uses' => function (ImplicitRouteBindingUser $user) {
            return $user;
        }];

        $route = new Route('GET', '/test/{user}', $action);
        $route->bind(Request::create('/test/1'));
        $route->setParameter('user', new ImplicitRouteBindingUser);

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);
    }

    #[DataProvider('shouldReportProvider')]
    public function testItThrowsModelNotFoundExceptionOnInvalidValueException(bool $shouldReport): void
    {
        Model::reportRouteModelBindingExceptions($shouldReport);

        $mock = m::mock(ExceptionHandler::class);

        if ($shouldReport) {
            $mock->shouldReceive('report')->once()->with(m::type(InvalidValueException::class));
        } else {
            $mock->shouldReceive('report')->never();
        }

        $container = Container::getInstance();
        $container->instance(ExceptionHandler::class, $mock);

        $action = ['uses' => fn (ImplicitRouteBindingUserWithInvalidValue $user): ImplicitRouteBindingUserWithInvalidValue => $user];

        $route = new Route('GET', '/test/{user}', $action);
        $route->bind(Request::create('/test/invalid-value'));
        $route->prepareForSerialization();
        $this->expectException(ModelNotFoundException::class);

        ImplicitRouteBinding::resolveForRoute($container, $route);
    }

    /**
     * Provide whether invalid value exceptions should be reported.
     *
     * @return list<array{0: bool}>
     */
    public static function shouldReportProvider(): array
    {
        return [[true], [false]];
    }

    public function testItDoesNotConvertUnrelatedQueryExceptions(): void
    {
        $mock = m::mock(ExceptionHandler::class);
        $mock->shouldReceive('report')->never();

        $container = Container::getInstance();
        $container->instance(ExceptionHandler::class, $mock);

        $action = ['uses' => fn (ImplicitRouteBindingUserWithQueryException $user): ImplicitRouteBindingUserWithQueryException => $user];

        $route = new Route('GET', '/test/{user}', $action);
        $route->bind(Request::create('/test/invalid-value'));
        $route->prepareForSerialization();

        $this->expectException(QueryException::class);

        ImplicitRouteBinding::resolveForRoute($container, $route);
    }

    public function testItConvertsInvalidValueExceptionsFromNonModelBindings(): void
    {
        $mock = m::mock(ExceptionHandler::class);
        $mock->shouldReceive('report')->once()->with(m::type(InvalidValueException::class));

        $container = Container::getInstance();
        $container->instance(ExceptionHandler::class, $mock);

        $route = new Route('GET', '/test/{resource}', ['uses' => fn (ImplicitRouteBindingResource $resource): ImplicitRouteBindingResource => $resource]);
        $route->bind(Request::create('/test/invalid-value'));

        $this->expectException(ModelNotFoundException::class);

        ImplicitRouteBinding::resolveForRoute($container, $route);
    }

    public function testItResolvesTrashedBindingsForNonModelBindings(): void
    {
        $route = (new Route('GET', '/test/{resource}', ['uses' => fn (ImplicitRouteBindingResource $resource): ImplicitRouteBindingResource => $resource]))->withTrashed();
        $route->bind(Request::create('/test/1'));

        ImplicitRouteBinding::resolveForRoute(Container::getInstance(), $route);

        $this->assertSame('1', $route->parameter('resource')->value);
    }

    public function testItResolvesScopedTrashedBindingsThroughNonModelParents(): void
    {
        $action = ['uses' => fn (ImplicitRouteBindingResource $resource, ImplicitRouteBindingSoftDeletableChild $child): ImplicitRouteBindingSoftDeletableChild => $child];

        $route = (new Route('GET', '/test/{resource}/{child}', $action))->scopeBindings()->withTrashed();
        $route->bind(Request::create('/test/1/2'));
        $route->setParameter('resource', new ImplicitRouteBindingResource);

        ImplicitRouteBinding::resolveForRoute(Container::getInstance(), $route);

        $this->assertSame('child:2', $route->parameter('child'));
    }

    public function testMissingNullBindingsReportNoIdentifiers(): void
    {
        $route = (new Route('GET', '/test/{resource?}', ['uses' => fn (?ImplicitRouteBindingResource $resource = null): ?ImplicitRouteBindingResource => $resource]))
            ->defaults('resource', null);
        $route->bind(Request::create('/test'));

        try {
            ImplicitRouteBinding::resolveForRoute(Container::getInstance(), $route);
        } catch (ModelNotFoundException $e) {
            $this->assertSame([], $e->getIds());
            $this->assertSame('No query results for model [' . ImplicitRouteBindingResource::class . '].', $e->getMessage());

            return;
        }

        $this->fail('No exception was thrown.');
    }

    public function testItUsesAFreshModelForEachImplicitRouteBinding(): void
    {
        $container = Container::getInstance();

        foreach ([1, 2] as $identifier) {
            $action = ['uses' => function (FreshImplicitRouteBindingUser $user) {
                return $user;
            }];
            $route = new Route('GET', '/test/{user}', $action);
            $route->bind(Request::create("/test/{$identifier}"));
            $route->prepareForSerialization();

            ImplicitRouteBinding::resolveForRoute($container, $route);

            $this->assertSame($identifier, $route->parameter('user')->getKey());
        }
    }

    public function testItResolvesInvokableObjectSignatureParameters(): void
    {
        $route = new Route(
            'GET',
            '/test/{category}',
            ['uses' => new ImplicitRouteBindingInvoker],
        );
        $route->bind(Request::create('/test/fruits'));

        ImplicitRouteBinding::resolveForRoute(Container::getInstance(), $route);

        $this->assertInstanceOf(CategoryBackedEnum::class, $route->parameter('category'));
        $this->assertSame('fruits', $route->parameter('category')->value);
    }

    public function testItDoesNotReuseStaleImplicitBindingSignatureParametersWhenClosureObjectIdIsReused(): void
    {
        $container = Container::getInstance();

        $closureWithNoParameters = function () {
            return 'ok';
        };
        $closureWithEnumParameter = function (CategoryBackedEnum $category) {
            return $category->value;
        };

        $staleSignature = [
            [],
            [],
        ];
        $this->seedImplicitBindingSignatureCache(
            $closureWithNoParameters,
            $staleSignature,
        );

        $route = new Route('GET', '/test/{category}', ['uses' => $closureWithEnumParameter]);
        $route->bind(Request::create('/test/fruits'));

        ImplicitRouteBinding::resolveForRoute($container, $route);

        $this->assertInstanceOf(CategoryBackedEnum::class, $route->parameter('category'));
        $this->assertSame('fruits', $route->parameter('category')->value);

        $reflectionProperty = new ReflectionProperty(ImplicitRouteBinding::class, 'objectSignatureCache');
        $cache = $reflectionProperty->getValue();

        $this->assertInstanceOf(WeakMap::class, $cache);
        $this->assertCount(2, $cache);
        $this->assertSame([[], []], $cache[$closureWithNoParameters]);
        $this->assertNotEmpty($cache[$closureWithEnumParameter][1]);
        $this->assertSame('category', $cache[$closureWithEnumParameter][1][0]->getName());
    }

    protected function seedImplicitBindingSignatureCache(
        Closure $staleClosure,
        array $signature,
    ): void {
        $reflectionProperty = new ReflectionProperty(ImplicitRouteBinding::class, 'objectSignatureCache');
        $cache = $reflectionProperty->getValue();

        if (! $cache instanceof WeakMap) {
            $cache = new WeakMap;
        }

        $cache[$staleClosure] = $signature;
        $reflectionProperty->setValue(null, $cache);
    }
}

class ImplicitRouteBindingUser extends Model
{
}

class ImplicitRouteBindingUserWithInvalidValue extends Model
{
    /**
     * Retrieve the model for a bound value.
     */
    public function resolveRouteBinding(mixed $value, ?string $field = null): never
    {
        throw new InvalidValueException('pgsql', 'select * from users where id = ?', [$value], new Exception('Out of range value'));
    }
}

class ImplicitRouteBindingUserWithQueryException extends Model
{
    /**
     * Retrieve the model for a bound value.
     */
    public function resolveRouteBinding(mixed $value, ?string $field = null): never
    {
        throw new QueryException('pgsql', 'select * from users where id = ?', [$value], new Exception('Undefined column'));
    }
}

class ImplicitRouteBindingResource implements UrlRoutable
{
    public mixed $value = null;

    /**
     * Get the value of the resource's route key.
     */
    public function getRouteKey(): mixed
    {
        return $this->value;
    }

    /**
     * Get the route key for the resource.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Retrieve the resource for a bound value.
     */
    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        if ($value === 'invalid-value') {
            throw new InvalidValueException('pgsql', 'select * from resources where id = ?', [$value], new Exception('Invalid text representation'));
        }

        if ($value === null) {
            return null;
        }

        $resource = new self;
        $resource->value = $value;

        return $resource;
    }

    /**
     * Retrieve the child for a bound value.
     */
    public function resolveChildRouteBinding(string $childType, mixed $value, ?string $field): string
    {
        return "{$childType}:{$value}";
    }
}

class ImplicitRouteBindingSoftDeletableChild extends Model
{
    use SoftDeletes;
}

class FreshImplicitRouteBindingUser extends Model
{
    private bool $resolved = false;

    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        if ($this->resolved) {
            throw new LogicException('The route binding model was reused.');
        }

        $this->resolved = true;

        return (new static)->setAttribute($this->getRouteKeyName(), $value);
    }
}

class EmptyParameterRoute extends Route
{
    public int $signatureParameterCalls = 0;

    public function signatureParameters(array|string $conditions = []): array
    {
        ++$this->signatureParameterCalls;

        return parent::signatureParameters($conditions);
    }
}

class ImplicitRouteBindingInvoker
{
    public function __invoke(CategoryBackedEnum $category): string
    {
        return $category->value;
    }
}
