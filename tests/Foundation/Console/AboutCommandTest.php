<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Closure;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Support\Composer;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;

class AboutCommandTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * @param Closure(bool):mixed $format
     */
    #[DataProvider('cliDataProvider')]
    public function testItCanFormatForCliInterface(Closure $format, mixed $expected): void
    {
        $this->assertSame($expected, value($format, false));
    }

    public static function cliDataProvider(): iterable
    {
        yield [AboutCommand::format(true, console: fn ($value) => $value === true ? 'YES' : 'NO'), 'YES'];
        yield [AboutCommand::format(false, console: fn ($value) => $value === true ? 'YES' : 'NO'), 'NO'];
    }

    /**
     * @param Closure(bool):mixed $format
     */
    #[DataProvider('jsonDataProvider')]
    public function testItCanFormatForJsonInterface(Closure $format, mixed $expected): void
    {
        $this->assertSame($expected, value($format, true));
    }

    public static function jsonDataProvider(): iterable
    {
        yield [AboutCommand::format(true, json: fn ($value) => $value === true ? 'YES' : 'NO'), 'YES'];
        yield [AboutCommand::format(false, json: fn ($value) => $value === true ? 'YES' : 'NO'), 'NO'];
    }

    public function testItReportsNoPhpFilesWhenGlobCannotEnumerateThePath(): void
    {
        $command = new TestableAboutCommand(Double::for(Composer::class));

        $this->assertFalse($command->hasPhpFiles(str_repeat('a', PHP_MAXPATHLEN + 1)));
    }
}

class TestableAboutCommand extends AboutCommand
{
    public function hasPhpFiles(string $path, string $extension = 'php'): bool
    {
        return parent::hasPhpFiles($path, $extension);
    }
}
