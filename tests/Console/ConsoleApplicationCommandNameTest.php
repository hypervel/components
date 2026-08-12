<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Application;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArgvInput;

class ConsoleApplicationCommandNameTest extends TestCase
{
    #[DataProvider('globalOptionCommandProvider')]
    public function testResolvesCommandNamesAfterGlobalOptions(array $arguments, string $command): void
    {
        $input = new ArgvInput(['artisan', ...$arguments]);

        $this->assertSame($command, Application::resolveCommandName($input));
    }

    /**
     * Provide commands containing only global options.
     *
     * @return array<string, array{list<string>, string}>
     */
    public static function globalOptionCommandProvider(): array
    {
        return [
            'direct command' => [['migrate'], 'migrate'],
            'attached environment value' => [['--env=production', 'migrate'], 'migrate'],
            'separated environment value' => [['--env', 'production', 'migrate'], 'migrate'],
            'short verbosity option' => [['-v', 'queue:work'], 'queue:work'],
            'long ANSI option' => [['--ansi', 'watch'], 'watch'],
        ];
    }

    #[DataProvider('commandOptionProvider')]
    public function testResolvesCommandNamesWhenPreliminaryBindingRejectsCommandOptions(
        array $arguments,
        string $command,
    ): void {
        $input = new ArgvInput(['artisan', ...$arguments]);

        $this->assertSame($command, Application::resolveCommandName($input));
    }

    /**
     * Provide commands containing options that are unavailable before command resolution.
     *
     * @return array<string, array{list<string>, string}>
     */
    public static function commandOptionProvider(): array
    {
        return [
            'command option' => [['serve', '--host', '0.0.0.0'], 'serve'],
            'environment value and command option' => [
                ['--env', 'production', 'serve', '--host=0.0.0.0'],
                'serve',
            ],
        ];
    }
}
