<?php

declare(strict_types=1);

namespace Hypervel\Tests\Hashing;

use Hypervel\Contracts\Hashing\Hasher as HasherContract;
use Hypervel\Hashing\AbstractHasher;
use Hypervel\Hashing\Argon2IdHasher;
use Hypervel\Hashing\ArgonHasher;
use Hypervel\Hashing\BcryptHasher;
use Hypervel\Hashing\HashManager;
use Hypervel\Support\Facades\Hash;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;
use ReflectionMethod;
use SensitiveParameter;

class SensitiveParameterTest extends TestCase
{
    public function testBcryptHelperPlaintextIsRedacted(): void
    {
        $parameter = (new ReflectionFunction('bcrypt'))->getParameters()[0];

        $this->assertSame('value', $parameter->getName());
        $this->assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
    }

    #[DataProvider('sensitiveParameters')]
    public function testPlaintextBearingParametersAreRedacted(
        string $class,
        string $method,
        string $parameterName
    ): void {
        $parameters = (new ReflectionMethod($class, $method))->getParameters();
        $parameter = null;

        foreach ($parameters as $candidate) {
            if ($candidate->getName() === $parameterName) {
                $parameter = $candidate;

                break;
            }
        }

        $this->assertNotNull($parameter);
        $this->assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
    }

    /**
     * Provide every named method parameter that can carry plaintext.
     *
     * @return array<string, array{class-string, string, string}>
     */
    public static function sensitiveParameters(): array
    {
        return [
            'hasher contract make plaintext' => [HasherContract::class, 'make', 'value'],
            'hasher contract check plaintext' => [HasherContract::class, 'check', 'value'],
            'abstract hasher check plaintext' => [AbstractHasher::class, 'check', 'value'],
            'argon make plaintext' => [ArgonHasher::class, 'make', 'value'],
            'argon check plaintext' => [ArgonHasher::class, 'check', 'value'],
            'argon2id check plaintext' => [Argon2IdHasher::class, 'check', 'value'],
            'bcrypt make plaintext' => [BcryptHasher::class, 'make', 'value'],
            'bcrypt check plaintext' => [BcryptHasher::class, 'check', 'value'],
            'manager make plaintext' => [HashManager::class, 'make', 'value'],
            'manager check plaintext' => [HashManager::class, 'check', 'value'],
            'manager hashed value' => [HashManager::class, 'isHashed', 'value'],
            'hash facade arguments' => [Hash::class, '__callStatic', 'args'],
        ];
    }
}
