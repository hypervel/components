<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Integration\Routing\Fixtures\CategoryBackedEnum;

class ImplicitBackedEnumRouteBindingTest extends RoutingTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set(['app.key' => 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF']);
    }

    public function testWithRouteCachingEnabled()
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php

use Hypervel\Tests\Integration\Routing\Fixtures\CategoryBackedEnum;

Route::get('/categories/{category}', function (CategoryBackedEnum $category) {
    return $category->value;
})->middleware('web');

Route::get('/categories-default/{category?}', function (CategoryBackedEnum $category = CategoryBackedEnum::Fruits) {
    return $category->value;
})->middleware('web');
PHP);

        $response = $this->get('/categories/fruits');
        $response->assertSee('fruits');

        $response = $this->get('/categories/people');
        $response->assertSee('people');

        $response = $this->get('/categories/cars');
        $response->assertNotFound(404);

        $response = $this->get('/categories-default/');
        $response->assertSee('fruits');

        $response = $this->get('/categories-default/people');
        $response->assertSee('people');

        $response = $this->get('/categories-default/fruits');
        $response->assertSee('fruits');
    }

    public function testWithoutRouteCachingEnabled()
    {
        config(['app.key' => str_repeat('a', 32)]);

        Route::post('/categories/{category}', function (CategoryBackedEnum $category) {
            return $category->value;
        })->middleware(['web']);

        Route::post('/categories-default/{category?}', function (CategoryBackedEnum $category = CategoryBackedEnum::Fruits) {
            return $category->value;
        })->middleware('web');

        Route::bind('categoryCode', fn (string $categoryCode) => CategoryBackedEnum::fromCode($categoryCode) ?? abort(404));

        Route::post('/categories-code/{categoryCode}', function (CategoryBackedEnum $categoryCode) {
            return $categoryCode->value;
        })->middleware(['web']);

        $response = $this->post('/categories/fruits');
        $response->assertSee('fruits');

        $response = $this->post('/categories/people');
        $response->assertSee('people');

        $response = $this->post('/categories/cars');
        $response->assertNotFound();

        $response = $this->post('/categories-default/');
        $response->assertSee('fruits');

        $response = $this->post('/categories-default/people');
        $response->assertSee('people');

        $response = $this->post('/categories-default/fruits');
        $response->assertSee('fruits');

        $response = $this->post('/categories-code/c01');
        $response->assertSee('people');

        $response = $this->post('/categories-code/c02');
        $response->assertSee('fruits');

        $response = $this->post('/categories-code/00');
        $response->assertNotFound();
    }
}
