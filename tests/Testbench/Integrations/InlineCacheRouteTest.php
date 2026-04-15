<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class InlineCacheRouteTest extends TestCase
{
    #[Test]
    public function itCanCacheRoute()
    {
        $this->assertFalse($this->app->routesAreCached());

        $this->defineCacheRoutes(<<<'PHP'
<?php

Route::get('stubs-controller', 'Workbench\App\Http\Controllers\ExampleController@index');
PHP);

        $this->get('stubs-controller')
            ->assertOk()
            ->assertSee('ExampleController@index');

        $this->assertTrue($this->app->routesAreCached());

        $this->reloadApplication();

        $this->assertFalse($this->app->routesAreCached());
    }
}
