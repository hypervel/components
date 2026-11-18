<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

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
        $actions = [];
        $stub = new RequiresHypervel($version);

        $stub->handle($this->app, function (string $method, array $parameters) use (&$actions): void {
            $actions[] = [$method, $parameters];
        });

        $this->assertSame([], $actions);
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
        $actions = [];
        $stub = new RequiresHypervel('<0.4.0');

        $stub->handle($this->app, function (string $method, array $parameters) use (&$actions): void {
            $actions[] = [$method, $parameters];
        });

        $this->assertSame([['markTestSkipped', ['Requires Hypervel Framework:<0.4.0']]], $actions);
    }
}
