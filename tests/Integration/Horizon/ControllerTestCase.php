<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Horizon\Horizon;

abstract class ControllerTestCase extends IntegrationTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Horizon::auth(function () {
            return true;
        });
    }
}
