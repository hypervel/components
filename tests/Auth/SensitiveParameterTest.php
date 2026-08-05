<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Passwords\CacheTokenRepository;
use Hypervel\Auth\Passwords\DatabaseTokenRepository;
use Hypervel\Auth\SessionGuard;
use Hypervel\Auth\TokenGuard;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use SensitiveParameter;

class SensitiveParameterTest extends TestCase
{
    #[DataProvider('sensitiveParameters')]
    public function testSecretBearingParametersAreRedacted(
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
     * Provide every Auth method parameter that can carry credentials or key material.
     *
     * @return array<string, array{class-string, string, string}>
     */
    public static function sensitiveParameters(): array
    {
        return [
            'token guard credentials' => [TokenGuard::class, 'validate', 'credentials'],
            'session guard constructor hash key' => [SessionGuard::class, '__construct', 'hashKey'],
            'session guard once credentials' => [SessionGuard::class, 'once', 'credentials'],
            'session guard validate credentials' => [SessionGuard::class, 'validate', 'credentials'],
            'session guard attempt credentials' => [SessionGuard::class, 'attempt', 'credentials'],
            'session guard attempt when credentials' => [SessionGuard::class, 'attemptWhen', 'credentials'],
            'session guard validation credentials' => [SessionGuard::class, 'hasValidCredentials', 'credentials'],
            'session guard attempt event credentials' => [SessionGuard::class, 'fireAttemptEvent', 'credentials'],
            'session guard failed event credentials' => [SessionGuard::class, 'fireFailedEvent', 'credentials'],
            'cache token repository hash key' => [CacheTokenRepository::class, '__construct', 'hashKey'],
            'database token repository hash key' => [DatabaseTokenRepository::class, '__construct', 'hashKey'],
        ];
    }
}
