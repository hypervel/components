<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class DefineCacheRoutesBeforeSetUpTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/before-setup-cache', fn () => 'before-setup-cache');
PHP);

        parent::setUp();
    }

    #[Test]
    public function itCanCacheRoutesBeforeParentSetup()
    {
        $this->assertFileExists($this->app->getCachedRoutesPath());

        $response = $this->get('/before-setup-cache');

        $response->assertOk();
        $this->assertSame('before-setup-cache', $response->getContent());
    }
}
