<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Testbench\Foundation\Bootstrap\SyncTestbenchCachedRoutes;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\refresh_router_lookups;

class BootstrapCachedRoutesTest extends TestCase
{
    private ?string $cachedRouteFile = null;

    #[Override]
    protected function tearDown(): void
    {
        if ($this->cachedRouteFile !== null && is_file($this->cachedRouteFile)) {
            unlink($this->cachedRouteFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function itLoadsCachedTestbenchRouteFilesIntoTheRouter(): void
    {
        $this->cachedRouteFile = $this->app->basePath(
            join_paths('routes', 'testbench-bootstrap-cached-routes-test.php')
        );

        file_put_contents(
            $this->cachedRouteFile,
            "<?php\n\nuse Hypervel\\Support\\Facades\\Route;\n\nRoute::get('/testbench-cached-route', fn () => 'ok')->name('testbench.cached.route');\n"
        );

        (new SyncTestbenchCachedRoutes)->bootstrap($this->app);

        refresh_router_lookups($this->app->make('router'));

        $this->assertTrue(
            $this->app->make('router')->has('testbench.cached.route')
        );
    }
}
