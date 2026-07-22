<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Closure;
use DateTime;
use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Console\RouteListCommand;
use Hypervel\Foundation\Testing\Concerns\InteractsWithDeprecationHandling;
use Hypervel\Http\RedirectResponse;
use Hypervel\Routing\Controller;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

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

    public function testDisplayRoutesForCli(): void
    {
        $this->withoutMockingConsoleOutput();

        RouteListCommand::resolveTerminalWidthUsing(static fn (): int => 200);

        $closureLine = __LINE__ + 1;
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

        $this->artisan(RouteListCommand::class);
        $output = Artisan::output();

        $this->assertStringContainsString('GET|HEAD', $output);
        $this->assertStringContainsString('closure', $output);
        $this->assertStringContainsString('controller-invokable', $output);
        $this->assertStringContainsString('controller-method/{user}', $output);
        $this->assertStringContainsString('RouteListCommandHelperTest.php:' . $closureLine, $output);
        $this->assertStringContainsString('Showing [6] routes', $output);
    }

    public function testDisplayRoutesForCliInVerboseMode(): void
    {
        $this->withoutMockingConsoleOutput();

        RouteListCommand::resolveTerminalWidthUsing(static fn (): int => 200);

        $closureLine = __LINE__ + 1;
        $this->router->get('closure', function () {
            return new RedirectResponse('/');
        });

        $this->router->get('controller-method/{user}', [RouteListFooController::class, 'show']);
        $this->router->post('controller-invokable', RouteListFooController::class);
        $this->router->domain('{account}.example.com')->group(function () {
            $this->router->get('user/{id}', function ($account, $id) {
            })->name('user.show')->middleware('web');
        });

        $this->artisan(RouteListCommand::class, ['-v' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('closure', $output);
        $this->assertStringContainsString('RouteListCommandHelperTest.php:' . $closureLine, $output);
        $this->assertStringContainsString('controller-invokable', $output);
        $this->assertStringContainsString('RouteListFooController@show', $output);
        $this->assertStringContainsString('user.show', $output);
        $this->assertStringContainsString('web', $output);
        $this->assertStringContainsString('Showing [4] routes', $output);
    }

    public function testRouteCanBeFilteredByName(): void
    {
        $this->withoutDeprecationHandling();
        $this->withoutMockingConsoleOutput();

        RouteListCommand::resolveTerminalWidthUsing(static fn (): int => 200);

        $this->router->get('/', function () {
        });
        $closureLine = __LINE__ + 1;
        $this->router->get('/foo', function () {
        })->name('foo.show');

        $this->artisan(RouteListCommand::class, ['--name' => 'foo']);
        $output = Artisan::output();

        $this->assertStringContainsString('foo', $output);
        $this->assertStringContainsString('foo.show', $output);
        $this->assertStringContainsString('RouteListCommandHelperTest.php:' . $closureLine, $output);
        $this->assertStringContainsString('Showing [1] routes', $output);
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

    public function testClosurePathIsDisplayedInVerboseMode(): void
    {
        $closureLine = __LINE__ + 1;
        $this->router->get('closure-path', function () {
        });

        $this->router->get('controller-method/{user}', [RouteListFooController::class, 'show']);

        $this->artisan(RouteListCommand::class, ['-v' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('RouteListCommandHelperTest.php:' . $closureLine);
    }

    public function testClosurePathIsDisplayedInNonVerboseMode(): void
    {
        RouteListCommand::resolveTerminalWidthUsing(static fn (): int => 200);

        $closureLine = __LINE__ + 1;
        $this->router->get('closure-path', function () {
        });

        $this->artisan(RouteListCommand::class)
            ->assertSuccessful()
            ->expectsOutputToContain('RouteListCommandHelperTest.php:' . $closureLine);
    }

    public function testClosurePathIsIncludedInJsonOutput(): void
    {
        $closureLine = __LINE__ + 1;
        $this->router->get('closure-path', function () {
        });

        $this->router->get('controller-method/{user}', [RouteListFooController::class, 'show']);

        $this->artisan(RouteListCommand::class, ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('RouteListCommandHelperTest.php:' . $closureLine);
    }

    public function testControllerRouteHasNullPathInJsonOutput(): void
    {
        $this->router->get('controller-method/{user}', [RouteListFooController::class, 'show']);

        $this->artisan(RouteListCommand::class, ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"path":null');
    }

    public function testInternalClosureRouteHasNullPath(): void
    {
        $this->router->get('internal-closure', Closure::fromCallable('phpversion'));

        $this->artisan(RouteListCommand::class, ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"path":null');
    }

    public function testInternalControllerClassDoesNotFailCliFormatting(): void
    {
        $this->router->get('internal-controller', [DateTime::class, 'createFromFormat']);

        $this->artisan(RouteListCommand::class)
            ->assertSuccessful()
            ->expectsOutputToContain('DateTime@createFromFormat');
    }

    public function testDisplayRoutesWithBindingFields(): void
    {
        $this->withoutMockingConsoleOutput();

        RouteListCommand::resolveTerminalWidthUsing(static fn (): int => 200);

        $this->router->get('users/{user:name}', [RouteListFooController::class, 'show']);
        $closureLine = __LINE__ + 1;
        $this->router->get('users/{user:name}/posts/{post:slug}', function () {
        });

        $this->artisan(RouteListCommand::class, ['-v' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('users/{user:name}', $output);
        $this->assertStringContainsString('RouteListFooController@show', $output);
        $this->assertStringContainsString('users/{user:name}/posts/{post:slug}', $output);
        $this->assertStringContainsString('RouteListCommandHelperTest.php:' . $closureLine, $output);
        $this->assertStringContainsString('Showing [2] routes', $output);
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
