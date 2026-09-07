<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon;

use Hypervel\Saloon\Traits\OAuth2\AuthorizationCodeGrant;
use Hypervel\Saloon\Traits\OAuth2\CreatesOAuthAuthenticator;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use SensitiveParameter;

class SensitiveParameterTest extends TestCase
{
    #[DataProvider('sensitiveParameters')]
    public function testOAuthTokenAndStateParametersAreSensitive(
        string $class,
        string $method,
        string $parameterName,
    ): void {
        $parameters = (new ReflectionMethod($class, $method))->getParameters();

        foreach ($parameters as $parameter) {
            if ($parameter->getName() === $parameterName) {
                $this->assertCount(1, $parameter->getAttributes(SensitiveParameter::class));

                return;
            }
        }

        $this->fail("Parameter [{$class}::{$method}(\${$parameterName})] does not exist.");
    }

    /**
     * Get secret-bearing OAuth parameters from internal call boundaries.
     *
     * @return list<array{class-string, string, string}>
     */
    public static function sensitiveParameters(): array
    {
        return [
            [CreatesOAuthAuthenticator::class, 'createOAuthAuthenticatorFromResponse', 'response'],
            [CreatesOAuthAuthenticator::class, 'createOAuthAuthenticatorFromResponse', 'fallbackRefreshToken'],
            [CreatesOAuthAuthenticator::class, 'createOAuthAuthenticator', 'accessToken'],
            [CreatesOAuthAuthenticator::class, 'createOAuthAuthenticator', 'refreshToken'],
            [AuthorizationCodeGrant::class, 'validateState', 'state'],
            [AuthorizationCodeGrant::class, 'validateState', 'expectedState'],
        ];
    }
}
