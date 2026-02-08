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
use Mockery as m;
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

    protected function getContainer()
    {
        $container = m::mock(ContainerInterface::class);
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
