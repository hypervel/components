<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Database\Eloquent\Events\Created;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelListener;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Psr\Container\ContainerInterface;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class ModelListenerTest extends TestCase
{
    public function testRegisterWithInvalidModelClass()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to find model class: NonExistentModel');

        $this->getModelListener()
            ->register('NonExistentModel', 'created', fn () => true);
    }

    public function testRegisterWithNonModelClass()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Class [stdClass] must extend Model.');

        $this->getModelListener()
            ->register(stdClass::class, 'created', fn () => true);
    }

    public function testRegisterWithInvalidEvent()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Event [invalid_event] is not a valid Eloquent event.');

        $this->getModelListener()
            ->register(ModelListenerTestUser::class, 'invalid_event', fn () => true);
    }

    public function testRegister()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(Created::class, m::type('array'));

        $listener = $this->getModelListener($dispatcher);
        $listener->register(ModelListenerTestUser::class, 'created', $callback = fn () => true);

        $this->assertSame(
            [$callback],
            $listener->getCallbacks(ModelListenerTestUser::class, 'created')
        );

        $this->assertSame(
            ['created' => [$callback]],
            $listener->getCallbacks(ModelListenerTestUser::class)
        );
    }

    public function testClear()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(Created::class, m::type('array'));

        $listener = $this->getModelListener($dispatcher);
        $listener->register(ModelListenerTestUser::class, 'created', fn () => true);

        $listener->clear(ModelListenerTestUser::class);

        $this->assertSame([], $listener->getCallbacks(ModelListenerTestUser::class));
    }

    public function testClearSpecificEvent()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(Created::class, m::type('array'));

        $listener = $this->getModelListener($dispatcher);
        $listener->register(ModelListenerTestUser::class, 'created', $callback = fn () => true);

        $listener->clear(ModelListenerTestUser::class, 'created');

        $this->assertSame([], $listener->getCallbacks(ModelListenerTestUser::class, 'created'));
    }

    public function testHandleEvent()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(Created::class, m::type('array'));

        $callbackModel = null;
        $listener = $this->getModelListener($dispatcher);
        $user = new ModelListenerTestUser();

        $listener->register(ModelListenerTestUser::class, 'created', function ($model) use (&$callbackModel) {
            $callbackModel = $model;
        });

        $listener->handleEvent(new Created($user));

        $this->assertSame($user, $callbackModel);
    }

    public function testHandleEventReturnsFalseWhenCallbackReturnsFalse()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(Created::class, m::type('array'));

        $listener = $this->getModelListener($dispatcher);
        $user = new ModelListenerTestUser();

        $listener->register(ModelListenerTestUser::class, 'created', fn () => false);

        $result = $listener->handleEvent(new Created($user));

        $this->assertFalse($result);
    }

    public function testRegisterObserverWithInvalidClass()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to find observer: NonExistentObserver');

        $this->getModelListener()
            ->registerObserver(ModelListenerTestUser::class, 'NonExistentObserver');
    }

    protected function getModelListener(?Dispatcher $dispatcher = null): ModelListener
    {
        return new ModelListener(
            m::mock(ContainerInterface::class),
            $dispatcher ?? m::mock(Dispatcher::class)
        );
    }
}

class ModelListenerTestUser extends Model
{
    protected ?string $table = 'users';
}
