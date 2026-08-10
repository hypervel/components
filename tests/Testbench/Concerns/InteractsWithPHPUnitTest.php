<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionException;

class InteractsWithPHPUnitTest extends TestCase
{
    #[Test]
    public function itCanResolveTheCorrectClassAndMethodName(): void
    {
        $this->assertSame(__CLASS__, $this->resolvePhpUnitTestClassName());
        $this->assertSame('itCanResolveTheCorrectClassAndMethodName', $this->resolvePhpUnitTestMethodName());
    }

    #[Test]
    public function itClearsAllCachedPhpUnitStateAfterClassTeardown(): void
    {
        InteractsWithPHPUnitTestCaseFixture::seedPhpUnitState();
        InteractsWithPHPUnitTestCaseFixture::runPhpUnitClassTeardown();

        $this->assertSame([
            'uses' => [],
            'classAttributes' => [],
            'methodAttributes' => [],
        ], InteractsWithPHPUnitTestCaseFixture::phpUnitState());
    }

    #[Test]
    public function itPropagatesAttributeReflectionFailures(): void
    {
        $this->expectException(ReflectionException::class);

        InteractsWithPHPUnitTestCaseFixture::resolveAttributesFor('missingMethod');
    }
}

class InteractsWithPHPUnitTestCaseFixture extends TestCase
{
    public static function seedPhpUnitState(): void
    {
        static::$cachedTestCaseUses = [self::class => [self::class => self::class]];
        static::$cachedTestCaseClassAttributes = [self::class => []];
        static::$cachedTestCaseMethodAttributes = [self::class . ':testPlaceholder' => []];
    }

    public static function runPhpUnitClassTeardown(): void
    {
        static::tearDownAfterClassUsingPHPUnit();
    }

    public static function phpUnitState(): array
    {
        return [
            'uses' => static::$cachedTestCaseUses,
            'classAttributes' => static::$cachedTestCaseClassAttributes,
            'methodAttributes' => static::$cachedTestCaseMethodAttributes,
        ];
    }

    public static function resolveAttributesFor(string $method): void
    {
        static::resolvePhpUnitAttributesForMethod(static::class, $method);
    }

    public function testPlaceholder(): void
    {
    }
}
