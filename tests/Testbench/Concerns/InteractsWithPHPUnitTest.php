<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

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
            'uses' => null,
            'classAttributes' => [],
            'methodAttributes' => [],
        ], InteractsWithPHPUnitTestCaseFixture::phpUnitState());
    }
}

class InteractsWithPHPUnitTestCaseFixture extends TestCase
{
    public static function seedPhpUnitState(): void
    {
        static::$cachedTestCaseUses = [self::class => self::class];
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

    public function testPlaceholder(): void
    {
    }
}
