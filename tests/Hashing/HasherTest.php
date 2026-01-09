<?php

declare(strict_types=1);

namespace Hypervel\Tests\Hashing;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\ContainerInterface;
use Hypervel\Hashing\Argon2IdHasher;
use Hypervel\Hashing\ArgonHasher;
use Hypervel\Hashing\BcryptHasher;
use Hypervel\Hashing\HashManager;
use Hypervel\Tests\TestCase;
use Mockery;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class HasherTest extends TestCase
{
    public HashManager $hashManager;

    public function setUp(): void
    {
        parent::setUp();

        $this->hashManager = new HashManager($this->getContainer());
    }

    public function testEmptyHashedValueReturnsFalse()
    {
        $hasher = new BcryptHasher();
        $this->assertFalse($hasher->check('password', ''));
        $hasher = new ArgonHasher();
        $this->assertFalse($hasher->check('password', ''));
        $hasher = new Argon2IdHasher();
        $this->assertFalse($hasher->check('password', ''));
    }

    public function testNullHashedValueReturnsFalse()
    {
        $hasher = new BcryptHasher();
        $this->assertFalse($hasher->check('password', null));
        $hasher = new ArgonHasher();
        $this->assertFalse($hasher->check('password', null));
        $hasher = new Argon2IdHasher();
        $this->assertFalse($hasher->check('password', null));
    }

    public function testBasicBcryptHashing()
    {
        $hasher = new BcryptHasher();
        $value = $hasher->make('password');
        $this->assertNotSame('password', $value);
        $this->assertTrue($hasher->check('password', $value));
        $this->assertFalse($hasher->needsRehash($value));
        $this->assertTrue($hasher->needsRehash($value, ['rounds' => 1]));
        $this->assertSame('bcrypt', password_get_info($value)['algoName']);
        $this->assertTrue($this->hashManager->isHashed($value));
    }

    public function testBasicArgon2iHashing()
    {
        $hasher = new ArgonHasher();
        $value = $hasher->make('password');
        $this->assertNotSame('password', $value);
        $this->assertTrue($hasher->check('password', $value));
        $this->assertFalse($hasher->needsRehash($value));
        $this->assertTrue($hasher->needsRehash($value, ['threads' => 1]));
        $this->assertSame('argon2i', password_get_info($value)['algoName']);
        $this->assertTrue($this->hashManager->isHashed($value));
    }

    public function testBasicArgon2idHashing()
    {
        $hasher = new Argon2IdHasher();
        $value = $hasher->make('password');
        $this->assertNotSame('password', $value);
        $this->assertTrue($hasher->check('password', $value));
        $this->assertFalse($hasher->needsRehash($value));
        $this->assertTrue($hasher->needsRehash($value, ['threads' => 1]));
        $this->assertSame('argon2id', password_get_info($value)['algoName']);
        $this->assertTrue($this->hashManager->isHashed($value));
    }

    /**
     * @depends testBasicBcryptHashing
     */
    public function testBasicBcryptVerification()
    {
        $this->expectException(RuntimeException::class);

        $argonHasher = new ArgonHasher(['verify' => true]);
        $argonHashed = $argonHasher->make('password');
        (new BcryptHasher(['verify' => true]))->check('password', $argonHashed);
    }

    /**
     * @depends testBasicArgon2iHashing
     */
    public function testBasicArgon2iVerification()
    {
        $this->expectException(RuntimeException::class);

        $bcryptHasher = new BcryptHasher(['verify' => true]);
        $bcryptHashed = $bcryptHasher->make('password');
        (new ArgonHasher(['verify' => true]))->check('password', $bcryptHashed);
    }

    /**
     * @depends testBasicArgon2idHashing
     */
    public function testBasicArgon2idVerification()
    {
        $this->expectException(RuntimeException::class);

        $bcryptHasher = new BcryptHasher(['verify' => true]);
        $bcryptHashed = $bcryptHasher->make('password');
        (new Argon2IdHasher(['verify' => true]))->check('password', $bcryptHashed);
    }

    public function testIsHashedWithNonHashedValue()
    {
        $this->assertFalse($this->hashManager->isHashed('foo'));
    }

    public function testBcryptVerifyConfigurationWithValidHash()
    {
        $hasher = new BcryptHasher(['rounds' => 10]);
        $hash = $hasher->make('password');

        $this->assertTrue($hasher->verifyConfiguration($hash));
    }

    public function testBcryptVerifyConfigurationWithLowerCost()
    {
        // Hash created with cost 4
        $lowCostHasher = new BcryptHasher(['rounds' => 4]);
        $hash = $lowCostHasher->make('password');

        // Verify with hasher configured for cost 10 - should pass (lower is ok)
        $higherCostHasher = new BcryptHasher(['rounds' => 10]);
        $this->assertTrue($higherCostHasher->verifyConfiguration($hash));
    }

    public function testBcryptVerifyConfigurationWithHigherCost()
    {
        // Hash created with cost 12
        $highCostHasher = new BcryptHasher(['rounds' => 12]);
        $hash = $highCostHasher->make('password');

        // Verify with hasher configured for cost 10 - should fail (higher than configured)
        $lowerCostHasher = new BcryptHasher(['rounds' => 10]);
        $this->assertFalse($lowerCostHasher->verifyConfiguration($hash));
    }

    public function testBcryptVerifyConfigurationWithWrongAlgorithm()
    {
        $argonHasher = new ArgonHasher();
        $argonHash = $argonHasher->make('password');

        $bcryptHasher = new BcryptHasher();
        $this->assertFalse($bcryptHasher->verifyConfiguration($argonHash));
    }

    public function testArgonVerifyConfigurationWithValidHash()
    {
        $hasher = new ArgonHasher(['memory' => 1024, 'time' => 2, 'threads' => 2]);
        $hash = $hasher->make('password');

        $this->assertTrue($hasher->verifyConfiguration($hash));
    }

    public function testArgonVerifyConfigurationWithLowerOptions()
    {
        // Hash created with lower options
        $lowOptionsHasher = new ArgonHasher(['memory' => 512, 'time' => 1, 'threads' => 1]);
        $hash = $lowOptionsHasher->make('password');

        // Verify with hasher configured for higher options - should pass
        $higherOptionsHasher = new ArgonHasher(['memory' => 1024, 'time' => 2, 'threads' => 2]);
        $this->assertTrue($higherOptionsHasher->verifyConfiguration($hash));
    }

    public function testArgonVerifyConfigurationWithHigherMemory()
    {
        // Hash created with higher memory
        $highMemoryHasher = new ArgonHasher(['memory' => 2048, 'time' => 2, 'threads' => 2]);
        $hash = $highMemoryHasher->make('password');

        // Verify with hasher configured for lower memory - should fail
        $lowerMemoryHasher = new ArgonHasher(['memory' => 1024, 'time' => 2, 'threads' => 2]);
        $this->assertFalse($lowerMemoryHasher->verifyConfiguration($hash));
    }

    public function testArgonVerifyConfigurationWithWrongAlgorithm()
    {
        $bcryptHasher = new BcryptHasher();
        $bcryptHash = $bcryptHasher->make('password');

        $argonHasher = new ArgonHasher();
        $this->assertFalse($argonHasher->verifyConfiguration($bcryptHash));
    }

    public function testArgon2idVerifyConfigurationWithValidHash()
    {
        $hasher = new Argon2IdHasher(['memory' => 1024, 'time' => 2, 'threads' => 2]);
        $hash = $hasher->make('password');

        $this->assertTrue($hasher->verifyConfiguration($hash));
    }

    public function testArgon2idVerifyConfigurationWithWrongAlgorithm()
    {
        // Use Argon2i hash with Argon2id hasher
        $argonHasher = new ArgonHasher();
        $argonHash = $argonHasher->make('password');

        $argon2idHasher = new Argon2IdHasher();
        $this->assertFalse($argon2idHasher->verifyConfiguration($argonHash));
    }

    public function testHashManagerVerifyConfigurationDelegatesToDriver()
    {
        $hash = $this->hashManager->make('password');
        $this->assertTrue($this->hashManager->verifyConfiguration($hash));
    }

    protected function getContainer()
    {
        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('get')
            ->with(ConfigInterface::class)
            ->andReturn($config = new Config([
                'hashing' => [
                    'driver' => 'bcrypt',
                    'bcrypt' => [
                        'rounds' => 10,
                    ],
                    'argon' => [
                        'memory' => 65536,
                        'threads' => 1,
                        'time' => 4,
                    ],
                ],
            ]));

        return $container;
    }
}
