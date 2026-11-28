<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit\Fixtures;

use Hypervel\Database\Connection;
use Hypervel\Database\PdoConnection;
use Hypervel\Telescope\Avatar;
use Hypervel\Telescope\Telescope;
use Hypervel\Testing\ParallelRunner;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Depends;

class SerialCleanupFixture extends TestCase
{
    public function testRegisterStateWithoutLoadingTheWrappers(): void
    {
        Avatar::register(static fn (): string => 'custom');
        Connection::resolverFor('cleanup', static fn (): null => null);

        $this->assertSame('custom', Avatar::url([]));
        $this->assertNotNull(Connection::getResolver('cleanup'));
        $this->assertFalse(class_exists(Telescope::class, false));
        $this->assertFalse(class_exists(PdoConnection::class, false));
        $this->assertFalse(class_exists(ParallelRunner::class, false));
    }

    #[Depends('testRegisterStateWithoutLoadingTheWrappers')]
    public function testCleanupResetsTheOwnersWithoutLoadingTheWrappers(): void
    {
        $this->assertNull(Avatar::url([]));
        $this->assertNull(Connection::getResolver('cleanup'));
        $this->assertFalse(class_exists(Telescope::class, false));
        $this->assertFalse(class_exists(PdoConnection::class, false));
        $this->assertFalse(class_exists(ParallelRunner::class, false));
    }
}
