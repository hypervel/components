<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\DataPipes\FillRouteParameterPropertiesDataPipeTest;

use Hypervel\Container\Attributes\RouteParameter;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Concerns\BindsRouteParameters;
use Hypervel\Tests\Data\Fixtures\NestedData;

class FillRouteParameterPropertiesDataPipeTest extends TestCase
{
    use BindsRouteParameters;

    // Upstream's deprecated FillRouteParameterPropertiesDataPipe is not included; RouteParameter supplies these values.
    // REMOVED: 'replaces properties when route parameter properties exist'; covered by InjectPropertyValuesTest.
    // REMOVED: 'skips replacing properties when route parameter properties exist and replacing is disabled' and
    // 'skips properties it cannot find a route parameter for'; a contextual value always wins, including null.
    // REMOVED: 'throws when trying to fill from a route parameter that has a scalar value'; covered by FromRouteParameterPropertyTest.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanFillDataPropertiesWithRouteParameters(): void
    {
        // URL parameters arrive as strings and are converted to the declared types.
        $request = $this->bindRouteParameters([
            'id' => '123',
            'slug' => 'foo-bar',
            'title' => 'Foo Bar',
            'tags' => ['foo', 'bar'],
            'nested' => ['simple' => ['string' => 'baz']],
        ]);

        $data = RouteParameterData::from($request);

        $this->assertSame(123, $data->id);
        $this->assertSame('foo-bar', $data->slug);
        $this->assertSame('Foo Bar', $data->title);
        $this->assertSame(['foo', 'bar'], $data->tags);
        $this->assertSame('baz', $data->nested->simple->string);
    }

    public function testCanFillDataPropertiesFromRouteParameterProperties(): void
    {
        $request = $this->bindRouteParameters([
            'foo' => (new RouteParameterModel)->forceFill(['id' => 123]),
            'bar' => ['name' => 'Baz'],
            'baz' => (object) ['description' => 'The bazzest bazz there is'],
        ]);

        $data = RouteParameterPropertyData::from($request);

        $this->assertSame(123, $data->id);
        $this->assertSame('Baz', $data->name);
        $this->assertSame('The bazzest bazz there is', $data->description);
    }

    public function testCanFillDataPropertiesFromRouteParametersUsingCustomPropertyMapping(): void
    {
        $request = $this->bindRouteParameters([
            'something' => [
                'name' => 'Something',
                'nested' => [
                    'foo' => 'bar',
                ],
                'tags' => ['foo', 'bar'],
                'rows' => [
                    ['total' => 10],
                    ['total' => 20],
                    ['total' => 30],
                ],
            ],
            'user_id' => '1',
        ]);

        $data = RouteParameterPathData::from($request);

        $this->assertSame('Something', $data->title);
        $this->assertSame('bar', $data->foo);
        $this->assertSame('foo', $data->tag);
        $this->assertSame([10, 20, 30], $data->totals);
        $this->assertSame(1, $data->userId);
    }
}

class RouteParameterData extends Data
{
    /**
     * Create a data object from route parameters.
     */
    public function __construct(
        #[RouteParameter('id')]
        public int $id,
        #[RouteParameter('slug')]
        public string $slug,
        #[RouteParameter('title')]
        public string $title,
        #[RouteParameter('tags')]
        public array $tags,
        #[RouteParameter('nested')]
        public NestedData $nested,
    ) {
    }
}

class RouteParameterModel extends Model
{
}

class RouteParameterPropertyData extends Data
{
    /**
     * Create a data object from route parameter properties.
     */
    public function __construct(
        #[RouteParameter('foo', property: 'id')]
        public int $id,
        #[RouteParameter('bar', property: 'name')]
        public string $name,
        #[RouteParameter('baz', property: 'description')]
        public string $description,
    ) {
    }
}

class RouteParameterPathData extends Data
{
    /**
     * Create a data object from paths into route parameters.
     */
    public function __construct(
        #[RouteParameter('something', property: 'name')]
        public string $title,
        #[RouteParameter('something', property: 'nested.foo')]
        public string $foo,
        #[RouteParameter('something', property: 'tags.0')]
        public string $tag,
        #[RouteParameter('something', property: 'rows.*.total')]
        public array $totals,
        #[RouteParameter('user_id'), MapInputName('user_id')]
        public int $userId,
    ) {
    }
}
