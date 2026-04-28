<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Support\Facades\Log;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class CacheRouteTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php

use Psr\Log\LoggerInterface;

Route::get('stubs-controller', 'Workbench\App\Http\Controllers\ExampleController@index');

Route::any('/logger', function (LoggerInterface $log) {
    $log->info('hello');
})->where(['all' => '.*']);
PHP);

        parent::setUp();
    }

    #[Test]
    public function itCanCacheRoute()
    {
        $this->get('stubs-controller')
            ->assertOk()
            ->assertSee('ExampleController@index');
    }

    #[Test]
    public function itCanCacheClosureRoute()
    {
        Log::spy()->shouldReceive('info')->with('hello');

        $this->get('logger')
            ->assertOk();
    }
}
