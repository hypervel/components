<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Session\EncryptedStore;
use Hypervel\Tests\TestCase;
use Mockery as m;
use SessionHandlerInterface;

/**
 * @internal
 * @coversNothing
 */
class EncryptedSessionStoreTest extends TestCase
{
    public function testSessionIsProperlyEncrypted()
    {
        $session = $this->getSession();
        $session->getEncrypter()->shouldReceive('decrypt')->once()->with(serialize([]))->andReturn(serialize([]));
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
        $session->start();
        $session->put('foo', 'bar');
        $session->flash('baz', 'boom');
        $session->now('qux', 'norf');
        $serialized = serialize([
            '_token' => $session->token(),
            'foo' => 'bar',
            'baz' => 'boom',
            '_flash' => [
                'new' => [],
                'old' => ['baz'],
            ],
        ]);
        $session->getEncrypter()->shouldReceive('encrypt')->once()->with($serialized)->andReturn($serialized);
        $session->getHandler()->shouldReceive('write')->once()->with(
            $this->getSessionId(),
            $serialized
        );
        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function getSession(): EncryptedStore
    {
        $store = new EncryptedStore(
            $this->getSessionName(),
            m::mock(SessionHandlerInterface::class),
            m::mock(Encrypter::class)
        );

        $store->setId($this->getSessionId());

        return $store;
    }

    protected function getSessionId(): string
    {
        return 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    }

    protected function getSessionName(): string
    {
        return 'name';
    }
}
