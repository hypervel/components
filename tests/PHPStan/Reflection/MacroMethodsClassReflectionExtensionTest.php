<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Reflection;

use Generator;
use Hypervel\Auth\AuthManager;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Hypervel\PHPStan\Methods\MacroMethodsClassReflectionExtension;
use Hypervel\PHPStan\Methods\WithHeadExtension;
use Hypervel\PHPStan\ReturnTypes\AuthGuardHelper;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Auth;
use Hypervel\Tests\PHPStan\Fixtures\Macros\MacroableAuthManager;
use Hypervel\Tests\PHPStan\Fixtures\Macros\MacroHost;
use Hypervel\Tests\PHPStan\Fixtures\Macros\NativeCallGuard;
use Hypervel\Validation\ValidationException;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\ClosureTypeFactory;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class MacroMethodsClassReflectionExtensionTest extends PHPStanTestCase
{
    private ReflectionProvider $reflectionProvider;

    private MacroMethodsClassReflectionExtension $reflectionExtension;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reflectionProvider = $this->createReflectionProvider();
        $this->reflectionExtension = new MacroMethodsClassReflectionExtension(
            $this->reflectionProvider,
            self::getContainer()->getByType(ClosureTypeFactory::class),
            self::getContainer()->getByType(WithHeadExtension::class),
            self::getContainer()->getByType(AuthGuardHelper::class),
        );
    }

    #[Test]
    #[DataProvider('methodAndClassProvider')]
    public function itCanFindMacrosOnAClass(string $class, string $methodName): void
    {
        $requestClass = $this->reflectionProvider->getClass($class);

        $this->assertTrue($this->reflectionExtension->hasMethod($requestClass, $methodName));
    }

    public static function methodAndClassProvider(): Generator
    {
        // Hypervel: Request's @method tags type its validation and signature macros; see taggedMethodProvider().
        yield [Collection::class, 'customCollectionMacro'];
        yield [MacroHost::class, 'invokableMacro'];
    }

    #[Test]
    #[DataProvider('taggedMethodProvider')]
    public function itLeavesTaggedMacrosToTheirTags(string $class, string $methodName): void
    {
        $this->assertFalse($this->reflectionExtension->hasMethod($this->reflectionProvider->getClass($class), $methodName));
    }

    public static function taggedMethodProvider(): Generator
    {
        yield [Request::class, 'validate'];
        yield [Request::class, 'validateWithBag'];
        yield [Request::class, 'hasValidSignature'];
        yield [Request::class, 'hasValidRelativeSignature'];
    }

    #[Test]
    #[DataProvider('methodAndThrowTypeProvider')]
    public function itCanSetThrowTypeForMacros(string $class, string $methodName, string $exceptionClass): void
    {
        // Hypervel: the @method tag types Request's macros, so MacroThrowTypeExtension reads their exceptions here.
        $throwType = $this->reflectionExtension->getThrowType($this->reflectionProvider->getClass($class), $methodName);

        $this->assertInstanceOf(ObjectType::class, $throwType);
        $this->assertSame($exceptionClass, $throwType->getClassName());
    }

    public static function methodAndThrowTypeProvider(): Generator
    {
        yield [Request::class, 'validate', ValidationException::class];
        yield [Request::class, 'validateWithBag', ValidationException::class];
    }

    #[Test]
    public function itOnlyGivesRequestMacrosTheValidationException(): void
    {
        $this->assertNull($this->reflectionExtension->getThrowType($this->reflectionProvider->getClass(MacroHost::class), 'validate'));
    }

    #[Test]
    public function itForwardsAGuardMacroThatEveryConfiguredGuardCanCall(): void
    {
        $extension = $this->createGuardMacroExtension();
        $manager = $this->reflectionProvider->getClass(AuthManager::class);
        $facade = $this->reflectionProvider->getClass(Auth::class);

        // RequestGuard registers guardMacro() and NativeCallGuard declares it, so either current guard has it.
        $this->assertTrue($extension->hasMethod($manager, 'guardMacro'));
        $this->assertTrue($extension->hasMethod($facade, 'guardMacro'));

        $method = $extension->getMethod($manager, 'guardMacro');

        // AuthManager has no __callStatic(), unlike the facade.
        $this->assertFalse($method->isStatic());
        $this->assertTrue($extension->getMethod($facade, 'guardMacro')->isStatic());
        $this->assertSame('int|string', $method->getVariants()[0]->getReturnType()->describe(VerbosityLevel::precise()));

        // NativeCallGuard has no requestGuardMacro().
        $this->assertFalse($extension->hasMethod($manager, 'requestGuardMacro'));
    }

    #[Test]
    public function itKeepsTheCallHandlerOfAnAuthManagerSubclass(): void
    {
        $extension = $this->createGuardMacroExtension();
        $manager = $this->reflectionProvider->getClass(MacroableAuthManager::class);

        MacroableAuthManager::macro('ownMacro', static fn (): string => '');

        $this->assertTrue($extension->hasMethod($manager, 'ownMacro'));
        $this->assertFalse($extension->hasMethod($manager, 'guardMacro'));
    }

    /**
     * Create the extension for an auth manager with a request guard and a custom guard.
     */
    private function createGuardMacroExtension(): MacroMethodsClassReflectionExtension
    {
        $app = new Container;
        $app->instance('config', new Repository(['auth' => ['guards' => [
            'request' => ['driver' => 'request'],
            'native' => ['driver' => 'native'],
        ]]]));

        $auth = new AuthManager($app);
        $auth->viaRequest('request', static fn () => null);
        $auth->extend('native', static fn (): NativeCallGuard => new NativeCallGuard);
        $app->instance('auth', $auth);

        $closureTypeFactory = self::getContainer()->getByType(ClosureTypeFactory::class);
        $guards = new AuthGuardHelper($this->reflectionProvider, $closureTypeFactory);
        $guards->setContainer($app);

        return new MacroMethodsClassReflectionExtension(
            $this->reflectionProvider,
            $closureTypeFactory,
            self::getContainer()->getByType(WithHeadExtension::class),
            $guards,
        );
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        // Hypervel: the test bootstrap registers the untagged fixture macros.
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
