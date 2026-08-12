<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Hypervel\Socialite\AbstractProvider as BaseProvider;
use Hypervel\Socialite\SocialiteManager;
use Hypervel\Socialite\Two\AbstractProvider;
use Hypervel\Socialite\Two\OpenIdProvider;
use Hypervel\Socialite\Two\Token;
use Hypervel\Socialite\Two\User;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use SensitiveParameter;

class SensitiveParameterTest extends TestCase
{
    public function testSecretBearingProviderParametersAreSensitive(): void
    {
        $manager = new ReflectionClass(SocialiteManager::class);
        $classes = [
            SocialiteManager::class,
            BaseProvider::class,
            AbstractProvider::class,
            OpenIdProvider::class,
            Token::class,
            User::class,
        ];

        foreach ($manager->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== SocialiteManager::class
                || preg_match('/^create.+Driver$/', $method->getName()) !== 1) {
                continue;
            }

            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
                $classes[] = $returnType->getName();
            }
        }

        $responseMethods = [
            'fake',
            'getUserByTokenResponse',
            'parseAccessToken',
            'parseApprovedScopes',
            'parseExpiresIn',
            'parseRefreshToken',
            'setAccessTokenResponseBody',
            'userInstance',
        ];
        $sensitiveNames = ['clientSecret', 'code', 'config', 'idToken', 'refreshToken', 'token'];
        $checked = [];
        $missing = [];

        foreach (array_unique($classes) as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    if (! in_array($parameter->getName(), $sensitiveNames, true)
                        && ! (in_array($method->getName(), $responseMethods, true)
                            && in_array($parameter->getName(), ['accessTokenResponseBody', 'attributes', 'response'], true))) {
                        continue;
                    }

                    $key = $method->getDeclaringClass()->getName()
                        . '::' . $method->getName()
                        . '($' . $parameter->getName() . ')';

                    if (isset($checked[$key])) {
                        continue;
                    }

                    $checked[$key] = true;

                    if ($parameter->getAttributes(SensitiveParameter::class) === []) {
                        $missing[] = $key;
                    }
                }
            }
        }

        $this->assertNotEmpty($checked);
        $this->assertSame([], $missing);
    }
}
