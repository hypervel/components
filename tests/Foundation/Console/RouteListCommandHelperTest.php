<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Console\RouteListCommand;
use Hypervel\Foundation\Testing\Concerns\InteractsWithDeprecationHandling;
use Hypervel\Http\RedirectResponse;
use Hypervel\Routing\Controller;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('filesystems.disks.local.serve', false)]
class RouteListCommandHelperTest extends TestCase
{
    use InteractsWithDeprecationHandling;

    private Registrar $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = $this->app->make(Registrar::class);

        RouteListCommand::resolveTerminalWidthUsing(function () {
            return 70;
        });
    }

    public function testDisplayRoutesForCli()
    {
        $this->router->get('/', function () {
        });

        $this->router->get('closure', function () {
            return new RedirectResponse('/');
        });

        $this->router->get('controller-method/{user}', [RouteListFooController::class, 'show']);
        $this->router->post('controller-invokable', RouteListFooController::class);
        $this->router->domain('{account}.example.com')->group(function () {
            $this->router->get('/', function () {
            });

            $this->router->get('user/{id}', function ($account, $id) {
            })->name('user.show')->middleware('web');
        });

        $this->artisan(RouteListCommand::class)
            ->assertSuccessful()
            ->expectsOutput('')
            ->expectsOutput('  GET|HEAD   {account}.example.com/ ................................ ')
            ->expectsOutput('  GET|HEAD   / ..................................................... ')
            ->expectsOutput('  GET|HEAD   closure ............................................... ')
            ->expectsOutput('  POST       controller-invokable Hypervel\Tests\Foundation\Console…')
            ->expectsOutput('  GET|HEAD   controller-method/{user} Hypervel\Tests\Foundation\Con…')
            ->expectsOutput('  GET|HEAD   {account}.example.com/user/{id} ............. user.show')
            ->expectsOutput('')
            ->expectsOutput('                                                  Showing [6] routes')
            ->expectsOutput('');
    }

    public function testDisplayRoutesForCliInVerboseMode()
    {
        $this->router->get('closure', function () {
            return new RedirectResponse('/');
        });

        $this->router->get('controller-method/{user}', [RouteListFooController::class, 'show']);
        $this->router->post('controller-invokable', RouteListFooController::class);
        $this->router->domain('{account}.example.com')->group(function () {
            $this->router->get('user/{id}', function ($account, $id) {
            })->name('user.show')->middleware('web');
        });

        $this->artisan(RouteListCommand::class, ['-v' => true])
            ->assertSuccessful()
            ->expectsOutput('')
            ->expectsOutput('  GET|HEAD   closure ............................................... ')
            ->expectsOutput('  POST       controller-invokable Hypervel\Tests\Foundation\Console\RouteListFooController')
            ->expectsOutput('  GET|HEAD   controller-method/{user} Hypervel\Tests\Foundation\Console\RouteListFooController@show')
            ->expectsOutput('  GET|HEAD   {account}.example.com/user/{id} ............. user.show')
            ->expectsOutput('             ⇂ web')
            ->expectsOutput('')
            ->expectsOutput('                                                  Showing [4] routes')
            ->expectsOutput('');
    }

    public function testRouteCanBeFilteredByName()
    {
        $this->withoutDeprecationHandling();

        $this->router->get('/', function () {
        });
        $this->router->get('/foo', function () {
        })->name('foo.show');

        $this->artisan(RouteListCommand::class, ['--name' => 'foo'])
            ->assertSuccessful()
            ->expectsOutput('')
            ->expectsOutput('  GET|HEAD       foo ...................................... foo.show')
            ->expectsOutput('')
            ->expectsOutput('                                                  Showing [1] routes')
            ->expectsOutput('');
    }

    public function testRouteCanBeFilteredByAction()
    {
        $this->withoutDeprecationHandling();

        RouteListCommand::resolveTerminalWidthUsing(function () {
            return 82;
        });

        $this->router->get('/', function () {
        });
        $this->router->get('foo/{user}', [RouteListFooController::class, 'show']);

        $this->artisan(RouteListCommand::class, ['--action' => 'RouteListFooController'])
            ->assertSuccessful()
            ->expectsOutput('')
            ->expectsOutput(
                '  GET|HEAD       foo/{user} Hypervel\Tests\Foundation\Console\RouteListFooContr…'
            )->expectsOutput('')
            ->expectsOutput(
                '                                                              Showing [1] routes'
            )
            ->expectsOutput('');
    }

    public function testDisplayRoutesExceptVendor()
    {
        $this->router->get('foo/{user}', [RouteListFooController::class, 'show']);
        $this->router->view('view', 'blade.path');
        $this->router->redirect('redirect', 'destination');

        $this->artisan(RouteListCommand::class, ['-v' => true, '--except-vendor' => true])
            ->assertSuccessful()
            ->expectsOutput('')
            ->expectsOutput('  GET|HEAD       foo/{user} Hypervel\Tests\Foundation\Console\RouteListFooController@show')
            ->expectsOutput('  ANY            redirect ...... Hypervel\Routing\RedirectController')
            ->expectsOutput('  GET|HEAD       view .............................................. ')
            ->expectsOutput('')
            ->expectsOutput('                                                  Showing [3] routes')
            ->expectsOutput('');
    }

    public function testDisplayRoutesWithBindingFields()
    {
        $this->router->get('users/{user:name}', [RouteListFooController::class, 'show']);
        $this->router->get('users/{user:name}/posts/{post:slug}', function () {
        });

        $this->artisan(RouteListCommand::class, ['-v' => true])
            ->assertSuccessful()
            ->expectsOutput('')
            ->expectsOutput('  GET|HEAD       users/{user:name} Hypervel\Tests\Foundation\Console\RouteListFooController@show')
            ->expectsOutput('  GET|HEAD       users/{user:name}/posts/{post:slug} ............... ')
            ->expectsOutput('')
            ->expectsOutput('                                                  Showing [2] routes')
            ->expectsOutput('');
    }

    public function testDisplayRoutesWithBindingFieldsAsJson()
    {
        $this->router->get('users/{user:name}/posts/{post:slug}', function () {
        });

        $this->artisan(RouteListCommand::class, ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('users\/{user:name}\/posts\/{post:slug}');
    }
}

class RouteListFooController extends Controller
{
    public function show(User $user)
    {
        // ..
    }

    public function __invoke()
    {
        // ..
    }
}
