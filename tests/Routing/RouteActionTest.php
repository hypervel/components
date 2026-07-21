<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RouteActionTest;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Routing\RouteAction;
use Hypervel\Tests\Routing\RoutingTestCase;
use Laravel\SerializableClosure\SerializableClosure;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

class RouteActionTest extends RoutingTestCase
{
    public function testItCanDetectASerializedClosure(): void
    {
        $callable = function (RouteActionUser $user) {
            return $user;
        };

        $action = ['uses' => serialize(
            new SerializableClosure($callable)
        )];

        $this->assertTrue(RouteAction::containsSerializedClosure($action));

        $action = ['uses' => 'FooController@index'];

        $this->assertFalse(RouteAction::containsSerializedClosure($action));
    }

    public function testItNormalizesObjectMethodActions(): void
    {
        $action = RouteAction::parse('test', [new RouteActionService, 'handle']);

        $this->assertInstanceOf(Closure::class, $action['uses']);
        $this->assertSame('handled', ($action['uses'])());
        $this->assertArrayNotHasKey('controller', $action);
    }

    public function testItNormalizesNestedObjectMethodActions(): void
    {
        $action = RouteAction::parse('test', [
            'uses' => [new RouteActionService, 'handle'],
            'middleware' => 'test',
        ]);

        $this->assertInstanceOf(Closure::class, $action['uses']);
        $this->assertSame('handled', ($action['uses'])());
        $this->assertSame('test', $action['middleware']);
        $this->assertArrayNotHasKey('controller', $action);
    }

    public function testItNormalizesClassMethodActions(): void
    {
        $controller = RouteActionService::class . '@handle';

        $this->assertSame(
            ['uses' => $controller, 'controller' => $controller],
            RouteAction::parse('test', [RouteActionService::class, 'handle']),
        );
    }

    public function testItNormalizesNestedClassMethodActions(): void
    {
        $controller = RouteActionService::class . '@handle';

        $this->assertSame(
            ['uses' => $controller, 'middleware' => 'test', 'controller' => $controller],
            RouteAction::parse('test', [
                'uses' => [RouteActionService::class, 'handle'],
                'middleware' => 'test',
            ]),
        );
    }

    #[DataProvider('unsupportedStringCallables')]
    public function testItRejectsNonControllerStringCallables(string $action): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Invalid route action: [{$action}].");

        RouteAction::parse('test', $action);
    }

    /**
     * Return callable strings that are not supported route-controller actions.
     *
     * @return iterable<string, array{string}>
     */
    public static function unsupportedStringCallables(): iterable
    {
        yield 'named function' => ['strlen'];
        yield 'static method' => [RouteActionService::class . '::staticHandle'];
    }
}

class RouteActionUser extends Model
{
}

class RouteActionService
{
    public function handle(): string
    {
        return 'handled';
    }

    public static function staticHandle(): void
    {
    }
}
