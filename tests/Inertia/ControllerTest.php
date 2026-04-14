<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Inertia\Controller;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\Fixtures\ExampleMiddleware;

/**
 * @internal
 * @coversNothing
 */
class ControllerTest extends TestCase
{
    public function testControllerReturnsAnInertiaResponse(): void
    {
        Route::middleware([StartSession::class, ExampleMiddleware::class])
            ->get('/', Controller::class)
            ->defaults('component', 'User/Edit')
            ->defaults('props', [
                'user' => ['name' => 'Jonathan'],
            ]);

        $response = $this->get('/');

        $this->assertEquals($response->viewData('page'), [
            'component' => 'User/Edit',
            'props' => [
                'user' => ['name' => 'Jonathan'],
                'errors' => (object) [],
            ],
            'url' => '/',
            'version' => '',
            'sharedProps' => ['errors'],
        ]);
    }
}
