<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Filesystem\join_paths;

#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
class RouteServiceProviderHealthTest extends TestCase
{
    protected function resolveApplication(): ApplicationContract
    {
        return Application::configure(static::applicationBasePath())
            ->withRouting(
                web: join_paths(__DIR__, 'Fixtures', 'web.php'),
                health: '/up',
            )->create();
    }

    #[Test]
    public function itCanLoadHealthPage()
    {
        $this->get('/up')->assertOk();
    }
}
