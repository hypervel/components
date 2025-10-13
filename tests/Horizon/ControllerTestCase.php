<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Hypervel\Horizon\Horizon;

abstract class ControllerTestCase extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');

        Horizon::auth(function () {
            return true;
        });
    }
}
