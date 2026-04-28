<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Console\Application;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Foundation\Console\RouteListCommand;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\Routing\Router;
use Hypervel\Tests\TestCase;
use Mockery as m;

class RouteListCommandTest extends TestCase
{
    protected Application $consoleApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consoleApp = new Application(
            $hypervel = new \Hypervel\Foundation\Application(__DIR__),
            m::mock(Dispatcher::class, ['dispatch' => null, 'fire' => null]),
            'testing',
        );

        $router = new Router(m::mock('Hypervel\Events\Dispatcher'));

        $kernel = new class($hypervel, $router) extends Kernel {
            protected array $middlewareGroups = [
                'web' => ['Middleware 1', 'Middleware 2', 'Middleware 5'],
                'auth' => ['Middleware 3', 'Middleware 4'],
            ];

            protected array $middlewarePriority = [
                'Middleware 1',
                'Middleware 4',
                'Middleware 2',
                'Middleware 3',
            ];
        };

        $kernel->prependToMiddlewarePriority('Middleware 5');

        $hypervel->instance(Kernel::class, $kernel);

        $router->get('/example', function () {
            return 'Hello World';
        })->middleware('exampleMiddleware');

        $router->get('/sub-example', function () {
            return 'Hello World';
        })->domain('sub')
            ->middleware('exampleMiddleware');

        $router->get('/example-group', function () {
            return 'Hello Group';
        })->middleware(['web', 'auth']);

        $command = new RouteListCommand($router);
        $command->setHypervel($hypervel);

        $this->consoleApp->addCommands([$command]);
    }

    public function testNoMiddlewareIfNotVerbose()
    {
        $this->consoleApp->call('route:list');
        $output = $this->consoleApp->output();

        $this->assertStringNotContainsString('exampleMiddleware', $output);
    }

    public function testSortRouteListAsc()
    {
        $this->consoleApp->call('route:list', ['--json' => true, '--sort' => 'domain,uri']);
        $output = $this->consoleApp->output();

        $expectedOrder = '[{"domain":null,"method":"GET|HEAD","uri":"example","name":null,"action":"Closure","middleware":["exampleMiddleware"]},{"domain":null,"method":"GET|HEAD","uri":"example-group","name":null,"action":"Closure","middleware":["web","auth"]},{"domain":"sub","method":"GET|HEAD","uri":"sub-example","name":null,"action":"Closure","middleware":["exampleMiddleware"]}]';

        $this->assertJsonStringEqualsJsonString($expectedOrder, $output);
    }

    public function testSortRouteListDesc()
    {
        $this->consoleApp->call('route:list', ['--json' => true, '--sort' => 'domain,uri', '--reverse' => true]);
        $output = $this->consoleApp->output();

        $expectedOrder = '[{"domain":"sub","method":"GET|HEAD","uri":"sub-example","name":null,"action":"Closure","middleware":["exampleMiddleware"]},{"domain":null,"method":"GET|HEAD","uri":"example-group","name":null,"action":"Closure","middleware":["web","auth"]},{"domain":null,"method":"GET|HEAD","uri":"example","name":null,"action":"Closure","middleware":["exampleMiddleware"]}]';

        $this->assertJsonStringEqualsJsonString($expectedOrder, $output);
    }

    public function testSortRouteListDefault()
    {
        $this->consoleApp->call('route:list', ['--json' => true]);
        $output = $this->consoleApp->output();

        $expectedOrder = '[{"domain":null,"method":"GET|HEAD","uri":"example","name":null,"action":"Closure","middleware":["exampleMiddleware"]},{"domain":null,"method":"GET|HEAD","uri":"example-group","name":null,"action":"Closure","middleware":["web","auth"]}, {"domain":"sub","method":"GET|HEAD","uri":"sub-example","name":null,"action":"Closure","middleware":["exampleMiddleware"]}]';

        $this->assertJsonStringEqualsJsonString($expectedOrder, $output);
    }

    public function testSortRouteListPrecedence()
    {
        $this->consoleApp->call('route:list', ['--json' => true, '--sort' => 'definition']);
        $output = $this->consoleApp->output();

        $expectedOrder = '[{"domain":null,"method":"GET|HEAD","uri":"example","name":null,"action":"Closure","middleware":["exampleMiddleware"]},{"domain":"sub","method":"GET|HEAD","uri":"sub-example","name":null,"action":"Closure","middleware":["exampleMiddleware"]}, {"domain":null,"method":"GET|HEAD","uri":"example-group","name":null,"action":"Closure","middleware":["web","auth"]}]';

        $this->assertJsonStringEqualsJsonString($expectedOrder, $output);
    }

    public function testMiddlewareGroupsAssignmentInCli()
    {
        $this->consoleApp->call('route:list', ['-v' => true]);
        $output = $this->consoleApp->output();

        $this->assertStringContainsString('exampleMiddleware', $output);
        $this->assertStringContainsString('web', $output);
        $this->assertStringContainsString('auth', $output);

        $this->assertStringNotContainsString('Middleware 1', $output);
        $this->assertStringNotContainsString('Middleware 2', $output);
        $this->assertStringNotContainsString('Middleware 3', $output);
        $this->assertStringNotContainsString('Middleware 4', $output);
        $this->assertStringNotContainsString('Middleware 5', $output);
    }

    public function testMiddlewareGroupsExpandInCliIfVeryVerbose()
    {
        $this->consoleApp->call('route:list', ['-vv' => true]);
        $output = $this->consoleApp->output();

        $this->assertStringContainsString('exampleMiddleware', $output);
        $this->assertStringContainsString('Middleware 1', $output);
        $this->assertStringContainsString('Middleware 2', $output);
        $this->assertStringContainsString('Middleware 3', $output);
        $this->assertStringContainsString('Middleware 4', $output);
        $this->assertStringContainsString('Middleware 5', $output);

        $this->assertStringNotContainsString('web', $output);
        $this->assertStringNotContainsString('auth', $output);
    }

    public function testMiddlewareGroupsAssignmentInJson()
    {
        $this->consoleApp->call('route:list', ['--json' => true, '-v' => true]);
        $output = $this->consoleApp->output();

        $this->assertStringContainsString('exampleMiddleware', $output);
        $this->assertStringContainsString('web', $output);
        $this->assertStringContainsString('auth', $output);

        $this->assertStringNotContainsString('Middleware 1', $output);
        $this->assertStringNotContainsString('Middleware 2', $output);
        $this->assertStringNotContainsString('Middleware 3', $output);
        $this->assertStringNotContainsString('Middleware 4', $output);
        $this->assertStringNotContainsString('Middleware 5', $output);
    }

    public function testMiddlewareGroupsExpandInJsonIfVeryVerbose()
    {
        $this->consoleApp->call('route:list', ['--json' => true, '-vv' => true]);
        $output = $this->consoleApp->output();

        $this->assertStringContainsString('exampleMiddleware', $output);
        $this->assertStringContainsString('Middleware 1', $output);
        $this->assertStringContainsString('Middleware 2', $output);
        $this->assertStringContainsString('Middleware 3', $output);
        $this->assertStringContainsString('Middleware 4', $output);
        $this->assertStringContainsString('Middleware 5', $output);

        $this->assertStringNotContainsString('web', $output);
        $this->assertStringNotContainsString('auth', $output);
    }

    public function testMiddlewareGroupsExpandCorrectlySortedIfVeryVerbose()
    {
        $this->consoleApp->call('route:list', ['--json' => true, '-vv' => true]);
        $output = $this->consoleApp->output();

        $expectedOrder = '[{"domain":null,"method":"GET|HEAD","uri":"example","name":null,"action":"Closure","middleware":["exampleMiddleware"]},{"domain":null,"method":"GET|HEAD","uri":"example-group","name":null,"action":"Closure","middleware":["Middleware 5","Middleware 1","Middleware 4","Middleware 2","Middleware 3"]},{"domain":"sub","method":"GET|HEAD","uri":"sub-example","name":null,"action":"Closure","middleware":["exampleMiddleware"]}]';

        $this->assertJsonStringEqualsJsonString($expectedOrder, $output);
    }

    public function testFilterByMiddleware()
    {
        $this->consoleApp->call('route:list', ['--json' => true, '-v' => true, '--middleware' => 'auth']);
        $output = $this->consoleApp->output();

        $expectedOrder = '[{"domain":null,"method":"GET|HEAD","uri":"example-group","name":null,"action":"Closure","middleware":["web","auth"]}]';

        $this->assertJsonStringEqualsJsonString($expectedOrder, $output);
    }
}
