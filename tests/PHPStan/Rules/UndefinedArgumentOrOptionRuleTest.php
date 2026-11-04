<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Internal\ConsoleApplicationResolver;
use Hypervel\PHPStan\Rules\ConsoleCommand\UndefinedArgumentOrOptionRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<UndefinedArgumentOrOptionRule> */
class UndefinedArgumentOrOptionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new UndefinedArgumentOrOptionRule(self::getContainer()->getByType(ConsoleApplicationResolver::class));
    }

    public function testRule(): void
    {
        $this->analyse([
            __DIR__ . '/../application/app/Console/Commands/FooCommand.php',
            __DIR__ . '/../application/app/Console/Commands/BarCommand.php',
            __DIR__ . '/../application/app/Console/Commands/BazCommand.php',
        ], [
            ['Command "foo" does not have argument "foobar".', 23],
            // Upstream accepts shortcuts, which option() throws for.
            ['Command "foo" does not have option "O".', 34],
            ['Command "foo" does not have option "v".', 38],
        ]);
    }

    public function testRuleWithCommandsRegisteredByTheConsoleKernel(): void
    {
        // Upstream's fixture console kernel is omitted: Hypervel applications have no kernel
        // class, and the kernel discovers app/Console/Commands, as withCommands() configures.
        $this->analyse([
            __DIR__ . '/../application/app/Console/Commands/RegisteredInKernelCommand.php',
            __DIR__ . '/../application/app/Console/Commands/LoadedByKernelCommand.php',
        ], [
            ['Command "registered-in-kernel" does not have argument "undefinedArgument".', 28],
            ['Command "registered-in-kernel" does not have option "undefinedOption".', 29],
            ['Command "loaded-by-kernel" does not have argument "undefinedArgument".', 28],
            ['Command "loaded-by-kernel" does not have option "undefinedOption".', 29],
        ]);
    }

    public function testRuleChecksTheReceiversCommands(): void
    {
        $this->analyse([
            __DIR__ . '/../Fixtures/Console/LookupCommand.php',
            __DIR__ . '/../Fixtures/Console/WithArgumentCommand.php',
            __DIR__ . '/../Type/data/console-commands.php',
        ], [
            ['Command "lookup:without" does not have argument "argument".', 20],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/phpstan-rules.neon',
        ];
    }
}
