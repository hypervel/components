<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Reflection;

use Hypervel\Http\RedirectResponse;
use Hypervel\PHPStan\Methods\RedirectResponseMethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class RedirectResponseMethodsClassReflectionExtensionTest extends PHPStanTestCase
{
    private ReflectionProvider $reflectionProvider;

    private RedirectResponseMethodsClassReflectionExtension $reflectionExtension;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reflectionProvider = $this->createReflectionProvider();
        $this->reflectionExtension = new RedirectResponseMethodsClassReflectionExtension;
    }

    #[Test]
    #[DataProvider('greenMethodProvider')]
    public function itWillFindMethodsStartingWithWith(string $methodName): void
    {
        $requestClass = $this->reflectionProvider->getClass(RedirectResponse::class);

        $this->assertTrue($this->reflectionExtension->hasMethod($requestClass, $methodName));
    }

    #[Test]
    #[DataProvider('redMethodProvider')]
    public function itWillNotFindMethods(string $methodName): void
    {
        $requestClass = $this->reflectionProvider->getClass(RedirectResponse::class);

        $this->assertFalse($this->reflectionExtension->hasMethod($requestClass, $methodName));
    }

    /** @return iterable<mixed> */
    public static function redMethodProvider(): iterable
    {
        yield ['non-existent'];
        yield ['aWith'];
        yield ['WithFoo'];
    }

    #[Test]
    #[DataProvider('greenMethodProvider')]
    public function itWillHaveCorrectMethodReflection(string $methodName): void
    {
        $requestClass = $this->reflectionProvider->getClass(RedirectResponse::class);
        $methodReflection = $this->reflectionExtension->getMethod($requestClass, $methodName);
        $parametersAcceptor = $methodReflection->getVariants()[0];

        $this->assertSame($methodName, $methodReflection->getName());
        $this->assertSame($requestClass, $methodReflection->getDeclaringClass());
        $this->assertFalse($methodReflection->isStatic());
        $this->assertFalse($methodReflection->isPrivate());
        $this->assertTrue($methodReflection->isPublic());
        $this->assertTrue($methodReflection->hasSideEffects()->yes());
        $this->assertCount(1, $parametersAcceptor->getParameters());
        $this->assertSame('mixed', $parametersAcceptor->getParameters()[0]->getType()->describe(VerbosityLevel::value()));
        $this->assertSame(RedirectResponse::class, $parametersAcceptor->getReturnType()->describe(VerbosityLevel::value()));
    }

    /** @return iterable<mixed> */
    public static function greenMethodProvider(): iterable
    {
        yield ['withFoo'];
        yield ['withFooAndBar'];
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../Rules/phpstan-rules.neon'];
    }
}
