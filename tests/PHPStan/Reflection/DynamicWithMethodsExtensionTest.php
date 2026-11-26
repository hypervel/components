<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Reflection;

use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Mail\Mailable;
use Hypervel\PHPStan\Methods\DynamicWithMethodsExtension;
use Hypervel\View\View;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class DynamicWithMethodsExtensionTest extends PHPStanTestCase
{
    private ReflectionProvider $reflectionProvider;

    private DynamicWithMethodsExtension $reflectionExtension;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reflectionProvider = $this->createReflectionProvider();
        $this->reflectionExtension = self::getContainer()->getByType(DynamicWithMethodsExtension::class);
    }

    /** @param class-string $className */
    #[Test]
    #[DataProvider('greenMethodProvider')]
    public function itWillFindMethodsStartingWithWith(string $className, string $methodName): void
    {
        $classReflection = $this->reflectionProvider->getClass($className);

        $this->assertTrue($this->reflectionExtension->hasMethod($classReflection, $methodName));
    }

    /** @param class-string $className */
    #[Test]
    #[DataProvider('redMethodProvider')]
    public function itWillNotFindMethods(string $className, string $methodName): void
    {
        $classReflection = $this->reflectionProvider->getClass($className);

        $this->assertFalse($this->reflectionExtension->hasMethod($classReflection, $methodName));
    }

    /** @return iterable<array{class-string, string}> */
    public static function redMethodProvider(): iterable
    {
        foreach ([RedirectResponse::class, View::class, Mailable::class] as $className) {
            yield [$className, 'non-existent'];
            yield [$className, 'aWith'];
            yield [$className, 'WithFoo'];
        }

        yield [Request::class, 'withFoo'];
    }

    /** @param class-string $className */
    #[Test]
    #[DataProvider('greenMethodProvider')]
    public function itWillHaveCorrectMethodReflection(string $className, string $methodName): void
    {
        $classReflection = $this->reflectionProvider->getClass($className);
        $methodReflection = $this->reflectionExtension->getMethod($classReflection, $methodName);
        $parametersAcceptor = $methodReflection->getVariants()[0];

        $this->assertSame($methodName, $methodReflection->getName());
        $this->assertSame($classReflection, $methodReflection->getDeclaringClass());
        $this->assertFalse($methodReflection->isStatic());
        $this->assertFalse($methodReflection->isPrivate());
        $this->assertTrue($methodReflection->isPublic());
        $this->assertTrue($methodReflection->hasSideEffects()->yes());
        $this->assertCount(1, $parametersAcceptor->getParameters());
        $this->assertSame('mixed', $parametersAcceptor->getParameters()[0]->getType()->describe(VerbosityLevel::value()));
        // Hypervel: the method returns the receiver's own type, keeping its template types.
        $this->assertSame('static(' . $className . ')', $parametersAcceptor->getReturnType()->describe(VerbosityLevel::value()));
    }

    /** @return iterable<array{class-string, string}> */
    public static function greenMethodProvider(): iterable
    {
        foreach ([RedirectResponse::class, View::class, Mailable::class] as $className) {
            yield [$className, 'withFoo'];
            yield [$className, 'withFooAndBar'];
        }
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../Rules/phpstan-rules.neon'];
    }
}
