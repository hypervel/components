<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon;

use Hypervel\Saloon\Http\Auth\CookieAuthenticator;
use Hypervel\Saloon\Traits\OAuth2\AuthorizationCodeGrant;
use Hypervel\Saloon\Traits\OAuth2\ClientCredentialsGrant;
use Hypervel\Saloon\Traits\OAuth2\ParsesOAuthTokenResponses;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use SensitiveParameter;

class SensitiveParameterTest extends TestCase
{
    #[DataProvider('sensitiveParameters')]
    public function testSecretParametersAreSensitive(
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
     * Get secret-bearing authentication parameters.
     *
     * @return list<array{class-string, string, string}>
     */
    public static function sensitiveParameters(): array
    {
        return [
            [CookieAuthenticator::class, '__construct', 'value'],
            [ParsesOAuthTokenResponses::class, 'parseOAuthTokenResponse', 'response'],
            [AuthorizationCodeGrant::class, 'createOAuthAuthenticatorFromResponse', 'response'],
            [AuthorizationCodeGrant::class, 'createOAuthAuthenticatorFromResponse', 'fallbackRefreshToken'],
            [AuthorizationCodeGrant::class, 'createOAuthAuthenticator', 'accessToken'],
            [AuthorizationCodeGrant::class, 'createOAuthAuthenticator', 'refreshToken'],
            [ClientCredentialsGrant::class, 'createOAuthAuthenticatorFromResponse', 'response'],
            [ClientCredentialsGrant::class, 'createOAuthAuthenticator', 'accessToken'],
            [AuthorizationCodeGrant::class, 'validateState', 'state'],
            [AuthorizationCodeGrant::class, 'validateState', 'expectedState'],
        ];
    }
}
