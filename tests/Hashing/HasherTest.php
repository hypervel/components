<?php

declare(strict_types=1);

namespace Hypervel\Tests\Hashing;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Hashing\Hasher as HasherContract;
use Hypervel\Hashing\Argon2IdHasher;
use Hypervel\Hashing\ArgonHasher;
use Hypervel\Hashing\BcryptHasher;
use Hypervel\Hashing\HashManager;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\Depends;
use RuntimeException;

class HasherTest extends TestCase
{
    public HashManager $hashManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hashManager = new HashManager($this->getContainer());
    }

    public function testEmptyHashedValueReturnsFalse(): void
    {
        $hasher = new BcryptHasher;
        $this->assertFalse($hasher->check('password', ''));
        $hasher = new ArgonHasher;
        $this->assertFalse($hasher->check('password', ''));
        $hasher = new Argon2IdHasher;
        $this->assertFalse($hasher->check('password', ''));
    }

    public function testNullHashedValueReturnsFalse(): void
    {
        $hasher = new BcryptHasher;
        $this->assertFalse($hasher->check('password', null));
        $hasher = new ArgonHasher;
        $this->assertFalse($hasher->check('password', null));
        $hasher = new Argon2IdHasher;
        $this->assertFalse($hasher->check('password', null));
    }

    public function testNullOrEmptyHashedValueDoesNotNeedRehash(): void
    {
        $hasher = new BcryptHasher;
        $this->assertFalse($hasher->needsRehash(null));
        $this->assertFalse($hasher->needsRehash(''));

        $hasher = new ArgonHasher;
        $this->assertFalse($hasher->needsRehash(null));
        $this->assertFalse($hasher->needsRehash(''));

        $hasher = new Argon2IdHasher;
        $this->assertFalse($hasher->needsRehash(null));
        $this->assertFalse($hasher->needsRehash(''));

        $this->assertFalse($this->hashManager->needsRehash(null));
        $this->assertFalse($this->hashManager->needsRehash(''));
    }

    public function testVerifiedHashersReturnFalseForNullOrEmptyHash(): void
    {
        $hasher = new BcryptHasher(['verify' => true]);
        $this->assertFalse($hasher->check('password', null));
        $this->assertFalse($hasher->check('password', ''));

        $hasher = new ArgonHasher(['verify' => true]);
        $this->assertFalse($hasher->check('password', null));
        $this->assertFalse($hasher->check('password', ''));

        $hasher = new Argon2IdHasher(['verify' => true]);
        $this->assertFalse($hasher->check('password', null));
        $this->assertFalse($hasher->check('password', ''));
    }

    public function testBasicBcryptHashing(): void
    {
        $hasher = new BcryptHasher;
        $value = $hasher->make('password');
        $this->assertNotSame('password', $value);
        $this->assertTrue($hasher->check('password', $value));
        $this->assertFalse($hasher->needsRehash($value));
        $this->assertTrue($hasher->needsRehash($value, ['rounds' => 1]));
        $this->assertSame('bcrypt', password_get_info($value)['algoName']);
        $this->assertGreaterThanOrEqual(12, password_get_info($value)['options']['cost']);
        $this->assertTrue($this->hashManager->isHashed($value));
    }

    public function testBcryptValueTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $hasher = new BcryptHasher(['limit' => '72']);
        $hasher->make(str_repeat('a', 73));
    }

    public function testNumericStringConfigurationValuesAreNormalized(): void
    {
        $bcrypt = new BcryptHasher([
            'rounds' => '4',
            'verify' => '0',
        ]);
        $bcryptHash = $bcrypt->make('password');

        $this->assertSame(4, password_get_info($bcryptHash)['options']['cost']);
        $this->assertFalse($bcrypt->check('password', 'not-a-hash'));

        $argon = new ArgonHasher([
            'memory' => '1024',
            'time' => '2',
            'threads' => '1',
            'verify' => '0',
        ]);
        $argonHash = $argon->make('password');

        $this->assertSame([
            'memory_cost' => 1024,
            'time_cost' => 2,
            'threads' => 1,
        ], password_get_info($argonHash)['options']);
        $this->assertFalse($argon->check('password', 'not-a-hash'));
    }

    public function testBasicArgon2iHashing(): void
    {
        $hasher = new ArgonHasher;
        $value = $hasher->make('password');
        $this->assertNotSame('password', $value);
        $this->assertTrue($hasher->check('password', $value));
        $this->assertFalse($hasher->needsRehash($value));
        $this->assertTrue($hasher->needsRehash($value, ['threads' => 1]));
        $this->assertSame('argon2i', password_get_info($value)['algoName']);
        $this->assertTrue($this->hashManager->isHashed($value));
    }

    public function testBasicArgon2idHashing(): void
    {
        $hasher = new Argon2IdHasher;
        $value = $hasher->make('password');
        $this->assertNotSame('password', $value);
        $this->assertTrue($hasher->check('password', $value));
        $this->assertFalse($hasher->needsRehash($value));
        $this->assertTrue($hasher->needsRehash($value, ['threads' => 1]));
        $this->assertSame('argon2id', password_get_info($value)['algoName']);
        $this->assertTrue($this->hashManager->isHashed($value));
    }

    #[Depends('testBasicBcryptHashing')]
    public function testBasicBcryptVerification(): void
    {
        $this->expectException(RuntimeException::class);

        $argonHasher = new ArgonHasher(['verify' => true]);
        $argonHashed = $argonHasher->make('password');
        (new BcryptHasher(['verify' => true]))->check('password', $argonHashed);
    }

    #[Depends('testBasicArgon2iHashing')]
    public function testBasicArgon2iVerification(): void
    {
        $this->expectException(RuntimeException::class);

        $bcryptHasher = new BcryptHasher(['verify' => true]);
        $bcryptHashed = $bcryptHasher->make('password');
        (new ArgonHasher(['verify' => true]))->check('password', $bcryptHashed);
    }

    #[Depends('testBasicArgon2idHashing')]
    public function testBasicArgon2idVerification(): void
    {
        $this->expectException(RuntimeException::class);

        $bcryptHasher = new BcryptHasher(['verify' => true]);
        $bcryptHashed = $bcryptHasher->make('password');
        (new Argon2IdHasher(['verify' => true]))->check('password', $bcryptHashed);
    }

    public function testIsHashedWithNonHashedValue(): void
    {
        $this->assertFalse($this->hashManager->isHashed('foo'));
    }

    public function testBasicBcryptNotSupported(): void
    {
        $this->expectException(RuntimeException::class);

        (new BcryptHasher(['rounds' => 0]))->make('password');
    }

    public function testBasicArgon2iNotSupported(): void
    {
        $this->expectException(RuntimeException::class);

        (new ArgonHasher(['time' => 0]))->make('password');
    }

    public function testBasicArgon2idNotSupported(): void
    {
        $this->expectException(RuntimeException::class);

        (new Argon2IdHasher(['time' => 0]))->make('password');
    }

    public function testIsHashedUsesTheConfiguredDriversInfoMethod(): void
    {
        $driver = m::mock(HasherContract::class);
        $driver->shouldReceive('info')
            ->once()
            ->with('custom-hash')
            ->andReturn(['algo' => 'custom']);

        $manager = new HashManager($this->getContainer(['driver' => 'custom']));
        $manager->extend('custom', fn () => $driver);

        $this->assertTrue($manager->isHashed('custom-hash'));
    }

    public function testAlgorithmVerificationUsesTheProtectedExtensionMethod(): void
    {
        $hasher = new class(['verify' => true]) extends BcryptHasher {
            protected function isUsingCorrectAlgorithm(string $hashedValue): bool
            {
                return true;
            }
        };

        $this->assertFalse($hasher->check('password', 'not-a-hash'));
    }

    public function testManagerFallsBackToDefaultHashingConfig(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('make')
            ->with('config')
            ->andReturn(new ConfigRepository([]));

        $manager = new HashManager($container);

        $this->assertSame('bcrypt', $manager->getDefaultDriver());
        $this->assertInstanceOf(BcryptHasher::class, $manager->createBcryptDriver());
        $this->assertInstanceOf(ArgonHasher::class, $manager->createArgonDriver());
        $this->assertInstanceOf(Argon2IdHasher::class, $manager->createArgon2idDriver());
    }

    protected function getContainer(?array $hashing = null): Container
    {
        $hashing ??= [
            'driver' => 'bcrypt',
            'bcrypt' => [
                'rounds' => 10,
            ],
            'argon' => [
                'memory' => 65536,
                'threads' => 1,
                'time' => 4,
            ],
        ];

        $container = m::mock(Container::class);
        $container->shouldReceive('make')
            ->with('config')
            ->andReturn($config = new ConfigRepository([
                'hashing' => $hashing,
            ]));

        return $container;
    }
}
