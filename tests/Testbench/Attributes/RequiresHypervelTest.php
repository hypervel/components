<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Exception;
use Hypervel\Testbench\Attributes\RequiresHypervel;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class RequiresHypervelTest extends TestCase
{
    #[Test]
    #[DataProvider('compatibleVersionDataProvider')]
    public function itCanValidateMatchingHypervelVersions(string $version): void
    {
        $stub = new RequiresHypervel($version);

        $stub->handle($this->app, function (): void {
            throw new Exception;
        });

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<int, array{0: string}>
     */
    public static function compatibleVersionDataProvider(): iterable
    {
        yield ['0.4'];
        yield ['^0.4'];
        yield ['>=0.4.0'];
    }

    #[Test]
    public function itCanInvalidateUnmatchedHypervelVersions(): void
    {
        $stub = new RequiresHypervel('<0.4.0');

        $stub->handle($this->app, function ($method, $parameters): void {
            $this->assertSame('markTestSkipped', $method);
            $this->assertSame(['Requires Hypervel Framework:<0.4.0'], $parameters);
        });
    }
}
