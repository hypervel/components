<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Session\EncryptedStore;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use SessionHandlerInterface;

class EncryptedSessionStoreTest extends TestCase
{
    use VerifiesDoubles;

    public function testSessionIsProperlyEncrypted(): void
    {
        $session = $this->getSession();
        $session->getEncrypter()->expects('decrypt')->with(serialize([]))->returns(serialize([]));
        $session->getHandler()->expects('read')->returns(serialize([]));
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
        $session->getEncrypter()->expects('encrypt')->with($serialized)->returns($serialized);
        $session->getHandler()->expects('write')->with(
            $this->getSessionId(),
            $serialized
        )->returns(true);
        $session->save();

        $this->assertFalse($session->isStarted());
    }

    /**
     * Create an encrypted session store.
     */
    public function getSession(): EncryptedStore
    {
        return new EncryptedStore(
            $this->getSessionName(),
            Double::for(SessionHandlerInterface::class),
            Double::for(Encrypter::class),
            $this->getSessionId()
        );
    }

    /**
     * Get the session ID.
     */
    protected function getSessionId(): string
    {
        return 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    }

    /**
     * Get the session name.
     */
    protected function getSessionName(): string
    {
        return 'name';
    }
}
