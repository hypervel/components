<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\Pennant\Attributes\Name;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\Middleware\EnsureFeaturesAreActive;
use Hypervel\Support\Facades\Blade;
use Hypervel\Tests\Pennant\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnumTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('pennant.default', 'array');
    }

    public function testActiveAndInactiveAcceptEnums(): void
    {
        Feature::define(FeatureEnum::NewApi, fn () => true);

        $this->assertTrue(Feature::active(FeatureEnum::NewApi));
        $this->assertFalse(Feature::inactive(FeatureEnum::NewApi));
    }

    public function testAnEnumAndItsBackingValueReferenceTheSameFeature(): void
    {
        Feature::activate(FeatureEnum::NewApi, 'foo');

        $this->assertSame('foo', Feature::value(FeatureEnum::NewApi));
        $this->assertSame('foo', Feature::value('new-api'));
        $this->assertTrue(Feature::active('new-api'));
    }

    public function testAPureEnumResolvesToItsCaseName(): void
    {
        Feature::activate(PureFeatureEnum::NewApi);

        $this->assertTrue(Feature::active(PureFeatureEnum::NewApi));
        $this->assertTrue(Feature::active('NewApi'));
    }

    public function testAnIntegerBackedEnumResolvesToItsStringBackingValue(): void
    {
        Feature::activate(IntegerFeatureEnum::NewApi, 'foo');

        $this->assertSame('foo', Feature::value(IntegerFeatureEnum::NewApi));
        $this->assertSame('foo', Feature::value('1'));
        $this->assertSame(['1' => 'foo'], Feature::values([IntegerFeatureEnum::NewApi]));
        $this->assertSame(['1' => ['foo']], Feature::load([IntegerFeatureEnum::NewApi]));
    }

    public function testDefineAcceptsAnEnumWithAResolver(): void
    {
        Feature::define(FeatureEnum::NewApi, fn () => 'resolved-value');

        $this->assertSame('resolved-value', Feature::value(FeatureEnum::NewApi));
        $this->assertSame('resolved-value', Feature::value('new-api'));
    }

    public function testValueAndValuesAcceptEnums(): void
    {
        Feature::define(FeatureEnum::NewApi, fn () => true);
        Feature::define(FeatureEnum::Purchasing, fn () => false);

        $this->assertSame([
            'new-api' => true,
            'purchasing' => false,
        ], Feature::values([FeatureEnum::NewApi, FeatureEnum::Purchasing]));
    }

    public function testAllAreActiveAndSomeAreActiveAcceptEnums(): void
    {
        Feature::activate(FeatureEnum::NewApi);
        Feature::activate(FeatureEnum::Purchasing);

        $this->assertTrue(Feature::allAreActive([FeatureEnum::NewApi, FeatureEnum::Purchasing]));
        $this->assertTrue(Feature::someAreActive([FeatureEnum::NewApi, FeatureEnum::Purchasing]));

        // Enums and their string values may be freely mixed in the same call.
        $this->assertTrue(Feature::allAreActive([FeatureEnum::NewApi, 'purchasing']));

        Feature::deactivate(FeatureEnum::Purchasing);

        $this->assertFalse(Feature::allAreActive([FeatureEnum::NewApi, FeatureEnum::Purchasing]));
        $this->assertTrue(Feature::someAreActive([FeatureEnum::NewApi, FeatureEnum::Purchasing]));
    }

    public function testAllAreInactiveAndSomeAreInactiveAcceptEnums(): void
    {
        Feature::activate(FeatureEnum::NewApi);
        Feature::deactivate(FeatureEnum::Purchasing);

        $this->assertTrue(Feature::allAreInactive([FeatureEnum::Purchasing]));
        $this->assertFalse(Feature::allAreInactive([FeatureEnum::NewApi, FeatureEnum::Purchasing]));
        $this->assertTrue(Feature::someAreInactive([FeatureEnum::NewApi, FeatureEnum::Purchasing]));
    }

    public function testWhenAndUnlessAcceptEnums(): void
    {
        Feature::activate(FeatureEnum::NewApi, 'foo');

        $this->assertSame('active-foo', Feature::when(
            FeatureEnum::NewApi,
            fn ($value) => "active-{$value}",
            fn () => 'inactive',
        ));

        $this->assertSame('inactive', Feature::unless(
            FeatureEnum::Purchasing,
            fn () => 'inactive',
            fn () => 'active',
        ));
    }

    public function testActivateDeactivateAndForgetAcceptEnums(): void
    {
        Feature::activate(FeatureEnum::NewApi);
        $this->assertTrue(Feature::active(FeatureEnum::NewApi));

        Feature::deactivate(FeatureEnum::NewApi);
        $this->assertFalse(Feature::active(FeatureEnum::NewApi));

        Feature::define(FeatureEnum::NewApi, fn () => true);
        Feature::forget(FeatureEnum::NewApi);
        $this->assertTrue(Feature::active(FeatureEnum::NewApi));
    }

    public function testLoadAndLoadMissingAcceptEnums(): void
    {
        Feature::define(FeatureEnum::NewApi, fn () => true);
        Feature::define(FeatureEnum::Purchasing, fn () => false);

        $this->assertSame([
            'new-api' => [true],
            'purchasing' => [false],
        ], Feature::loadMissing([FeatureEnum::NewApi, FeatureEnum::Purchasing]));

        $this->assertSame([
            'new-api' => [true],
        ], Feature::load([FeatureEnum::NewApi]));
    }

    public function testActivateForEveryoneAndPurgeAcceptEnums(): void
    {
        Feature::define(FeatureEnum::NewApi, fn () => false);

        // Resolve the scope so the array driver tracks it for "all scopes" updates.
        Feature::for('tim')->active(FeatureEnum::NewApi);

        Feature::activateForEveryone(FeatureEnum::NewApi);
        $this->assertTrue(Feature::for('tim')->active(FeatureEnum::NewApi));

        Feature::deactivateForEveryone(FeatureEnum::NewApi);
        $this->assertFalse(Feature::for('tim')->active(FeatureEnum::NewApi));

        Feature::activate(FeatureEnum::NewApi);
        Feature::purge(FeatureEnum::NewApi);
        $this->assertFalse(Feature::active(FeatureEnum::NewApi));
    }

    public function testScopedInteractionsAcceptEnums(): void
    {
        Feature::for('tim')->activate(FeatureEnum::NewApi);

        $this->assertTrue(Feature::for('tim')->active(FeatureEnum::NewApi));
        $this->assertTrue(Feature::for('tim')->active('new-api'));
        $this->assertFalse(Feature::for('jess')->active(FeatureEnum::NewApi));
    }

    public function testInstanceAcceptsAnEnum(): void
    {
        Feature::define(FeatureEnum::NewApi, $resolver = fn () => 'resolved');

        $this->assertSame($resolver, Feature::instance(FeatureEnum::NewApi));
        $this->assertSame($resolver, Feature::instance('new-api'));
    }

    public function testTheNameAttributeAcceptsAnEnum(): void
    {
        $this->assertSame('purchasing', Feature::name(FeatureWithEnumName::class));

        $this->assertTrue(Feature::active(FeatureWithEnumName::class));
        $this->assertTrue(Feature::active(FeatureEnum::Purchasing));
        $this->assertTrue(Feature::active('purchasing'));
    }

    public function testTheBladeDirectiveAcceptsAnEnum(): void
    {
        $blade = <<<'BLADE'
            @feature(\Hypervel\Tests\Pennant\Feature\FeatureEnum::NewApi)
                new api is active
            @else
                new api is inactive
            @endfeature
            BLADE;

        $this->assertSame('new api is inactive', trim(Blade::render($blade)));

        Feature::activate(FeatureEnum::NewApi);

        $this->assertSame('new api is active', trim(Blade::render($blade)));
    }

    public function testTheMiddlewareUsingMethodAcceptsEnums(): void
    {
        $this->assertSame(
            EnsureFeaturesAreActive::class . ':new-api,purchasing',
            EnsureFeaturesAreActive::using(FeatureEnum::NewApi, FeatureEnum::Purchasing),
        );
    }

    public function testTheMiddlewareHandleMethodAcceptsEnums(): void
    {
        Feature::define(FeatureEnum::NewApi, fn () => true);

        $response = (new EnsureFeaturesAreActive)->handle(
            $this->createRequest('test', 'get'),
            fn () => new Response,
            FeatureEnum::NewApi,
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->expectException(HttpException::class);

        (new EnsureFeaturesAreActive)->handle(
            $this->createRequest('test', 'get'),
            fn () => new Response,
            FeatureEnum::Purchasing,
        );
    }

    /**
     * Create a request for the given URI and method.
     */
    protected function createRequest(string $uri, string $method): Request
    {
        return Request::createFromBase(SymfonyRequest::create(uri: $uri, method: $method));
    }
}

enum FeatureEnum: string
{
    case NewApi = 'new-api';
    case Purchasing = 'purchasing';
}

enum IntegerFeatureEnum: int
{
    case NewApi = 1;
    case Purchasing = 2;
}

enum PureFeatureEnum
{
    case NewApi;
    case Purchasing;
}

#[Name(FeatureEnum::Purchasing)]
class FeatureWithEnumName
{
    public function resolve(): bool
    {
        return true;
    }
}
