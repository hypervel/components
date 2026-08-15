<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Http;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Telescope\Http\Middleware\Authorize;
use Hypervel\Tests\Telescope\FeatureTestCase;

class NullPathRouteTest extends FeatureTestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('telescope.path', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(Authorize::class);
    }

    public function testDashboardAndApiRoutesRegisterWithoutPrefix(): void
    {
        $this->get('/requests')
            ->assertSuccessful();

        $this->post('/telescope-api/mail')
            ->assertSuccessful()
            ->assertJsonStructure(['entries' => []]);
    }
}
