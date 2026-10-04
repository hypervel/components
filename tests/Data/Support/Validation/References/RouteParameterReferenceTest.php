<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation\References;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\Validation\Unique;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\CannotResolveRouteParameterReference;
use Hypervel\Data\Support\Validation\References\RouteParameterReference;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Concerns\BindsRouteParameters;

class RouteParameterReferenceTest extends TestCase
{
    use BindsRouteParameters;

    /**
     * Get package providers for the route parameter reference tests.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
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

        $this->assertSame('unique:posts,NULL,"1",id', (string) $first['property'][2]);
        $this->assertSame('unique:posts,NULL,"2",id', (string) $second['property'][2]);
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
