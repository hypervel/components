<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core;

use Hyperf\Database\Model\Events\Created;
use Hyperf\Database\Model\Model;
use Hypervel\Database\Eloquent\ModelListener;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class ModelListenerTest extends TestCase
{
    public function testRegisterWithInvalidModel()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to find model class: model');

        $this->getModelListener()
            ->register('model', 'event', fn () => true);
    }

    public function testRegisterWithInvalidEvent()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Event [event] is not a valid Eloquent event.');

        $this->getModelListener()
            ->register(new ModelUser(), 'event', fn () => true);
    }

    public function testRegister()
    {
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(Created::class, m::type('callable'));

        $manager = $this->getModelListener($dispatcher);
        $manager->register($user = new ModelUser(), 'created', $callback = fn () => true);

        $this->assertSame(
            [$callback],
            $manager->getCallbacks($user, 'created')
        );

        $this->assertSame(
            ['created' => [$callback]],
            $manager->getCallbacks($user)
        );
    }

    public function testClear()
    {
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(Created::class, m::type('callable'));

        $manager = $this->getModelListener($dispatcher);
        $manager->register($user = new ModelUser(), 'created', fn () => true);

        $manager->clear($user);

        $this->assertSame([], $manager->getCallbacks(new ModelUser()));
    }

    public function testHandleEvents()
    {
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(Created::class, m::type('callable'));

        $callbackUser = null;
        $manager = $this->getModelListener($dispatcher);
        $manager->register($user = new ModelUser(), 'created', function ($user) use (&$callbackUser) {
            $callbackUser = $user;
        });
        $manager->handleEvent(new Created($user));

        $this->assertSame($user, $callbackUser);
    }

    protected function getModelListener(?EventDispatcherInterface $dispatcher = null): ModelListener
    {
        return new ModelListener(
            $dispatcher ?? m::mock(EventDispatcherInterface::class)
        );
    }
}

class ModelUser extends Model
{
}
