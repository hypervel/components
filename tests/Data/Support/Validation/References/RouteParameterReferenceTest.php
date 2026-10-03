<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation\References;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\Validation\Unique;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\CannotResolveRouteParameterReference;
use Hypervel\Data\Support\Validation\Constraints\WhereConstraint;
use Hypervel\Data\Support\Validation\References\RouteParameterReference;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Testbench\TestCase;

class RouteParameterReferenceTest extends TestCase
{
    /**
     * Get package providers for the route parameter reference tests.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanUseExternalReferenceAsDatabaseConstraintValue(): void
    {
        $this->bindRouteParameters(['active' => true]);

        $rules = RouteParameterWhereConstraintData::getValidationRules([]);

        $this->assertSame('unique:users,NULL,NULL,id,is_active,"1"', (string) $rules['email'][0]);
        $this->assertSame(['required', 'string'], array_slice($rules['email'], 1));
    }

    public function testCanReferenceRouteParametersAsValuesWithinRules(): void
    {
        $this->bindRouteParameters(['post_id' => '69']);

        $rules = RouteParameterIgnoreData::getValidationRules([]);

        $this->assertSame('unique:posts,NULL,"69",id', (string) $rules['property'][0]);
    }

    public function testCanReferenceRouteModelsWithAPropertyAsValuesWithinRules(): void
    {
        $this->bindRouteParameters(['post' => RouteParameterPost::make(69)]);

        $rules = RouteParameterModelPropertyData::getValidationRules([]);

        $this->assertSame('unique:posts,NULL,"69",id', (string) $rules['property'][0]);
    }

    public function testResolvesTheWholeBoundModelWithoutAProperty(): void
    {
        $post = RouteParameterPost::make(69);
        $this->bindRouteParameters(['post' => $post]);

        $this->assertSame($post, (new RouteParameterReference('post'))->getValue());
    }

    public function testNullableReferenceResolvesAMissingParameterToNull(): void
    {
        $this->bindRouteParameters([]);

        $this->assertNull((new RouteParameterReference('post', nullable: true))->getValue());
    }

    public function testMissingParameterThrows(): void
    {
        $this->bindRouteParameters([]);

        $this->expectException(CannotResolveRouteParameterReference::class);
        $this->expectExceptionMessage('Cannot find route parameter post with property id');

        (new RouteParameterReference('post', 'id'))->getValue();
    }

    public function testMissingPropertyOnParameterThrows(): void
    {
        $this->bindRouteParameters(['post' => RouteParameterPost::make(69)]);

        $this->expectException(CannotResolveRouteParameterReference::class);
        $this->expectExceptionMessage('Cannot find property missing in route parameter post');

        (new RouteParameterReference('post', 'missing'))->getValue();
    }

    public function testEachValidationResolvesTheCurrentRequest(): void
    {
        $this->bindRouteParameters(['post_id' => '1']);
        $first = RouteParameterIgnoreData::getValidationRules([]);

        $this->bindRouteParameters(['post_id' => '2']);
        $second = RouteParameterIgnoreData::getValidationRules([]);

        $this->assertSame('unique:posts,NULL,"1",id', (string) $first['property'][0]);
        $this->assertSame('unique:posts,NULL,"2",id', (string) $second['property'][0]);
    }

    /**
     * Seed the current request with a route carrying the given parameters.
     *
     * @param array<string, mixed> $parameters
     */
    private function bindRouteParameters(array $parameters): void
    {
        $request = Request::create('/posts');
        $route = (new Route('GET', '/posts', static fn (): null => null))->bind($request);

        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(static fn (): Route => $route);
        RequestContext::set($request);
    }
}

class RouteParameterPost extends Model
{
    /**
     * Create an unsaved post with the given key.
     */
    public static function make(int $id): self
    {
        return (new self)->forceFill(['id' => $id]);
    }
}

class RouteParameterWhereConstraintData extends Data
{
    /**
     * Create a fixture constrained by a route parameter.
     */
    public function __construct(
        #[Unique('users', where: [new WhereConstraint('is_active', new RouteParameterReference('active'))])]
        public string $email,
    ) {
    }
}

class RouteParameterIgnoreData extends Data
{
    /**
     * Create a fixture that ignores a route parameter.
     */
    public function __construct(
        #[Unique('posts', ignore: new RouteParameterReference('post_id'))]
        public int $property,
    ) {
    }
}

class RouteParameterModelPropertyData extends Data
{
    /**
     * Create a fixture that ignores a route model's property.
     */
    public function __construct(
        #[Unique('posts', ignore: new RouteParameterReference('post', 'id'))]
        public int $property,
    ) {
    }
}
