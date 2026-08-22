<?php

declare(strict_types=1);

namespace Hypervel\Tests\Hashing;

use Hypervel\Hashing\Argon2IdHasher;
use Hypervel\Hashing\ArgonHasher;
use Hypervel\Hashing\BcryptHasher;
use Hypervel\Hashing\HashManager;
use Hypervel\Testbench\TestCase;

class HashingServiceProviderTest extends TestCase
{
    public function testShippedConfigurationResolvesAllBuiltInDrivers(): void
    {
        $manager = $this->app->make(HashManager::class);

        $this->assertInstanceOf(BcryptHasher::class, $manager->driver('bcrypt'));
        $this->assertInstanceOf(ArgonHasher::class, $manager->driver('argon'));
        $this->assertInstanceOf(Argon2IdHasher::class, $manager->driver('argon2id'));
    }
}
