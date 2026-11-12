<?php

namespace App\Console\Commands;

use Hypervel\Console\Command;

use function PHPStan\Testing\assertType;

class FooCommand extends Command
{
    protected ?string $signature = 'foo {argument} {optionalArg?} {optionalArgWithDefault=1} {--O|option} {--optionWithValue=}';

    protected string $description = 'Dummy command used to test console command types.';

    public function handle(): void
    {
        assertType('array{command: string, argument: string, optionalArg: string|null, optionalArgWithDefault: string}', $this->argument());
        assertType('array{command: string, argument: string, optionalArg: string|null, optionalArgWithDefault: string}', $this->arguments());
        assertType('array{command: string, argument: string, optionalArg: string|null, optionalArgWithDefault: string}', $this->argument(null));
        assertType('string', $this->argument('argument'));
        assertType('string|null', $this->argument('optionalArg'));
        assertType('string', $this->argument('optionalArgWithDefault'));
        assertType('mixed', $this->argument('foobar'));

        assertType('true', $this->hasArgument('argument'));
        assertType('true', $this->hasArgument('optionalArg'));
        assertType('true', $this->hasArgument('optionalArgWithDefault'));
        assertType('false', $this->hasArgument('foobar'));

        assertType('array{option: bool, optionWithValue: string|null, disable-event-dispatcher: bool, help: bool, silent: bool, quiet: bool, verbose: bool, version: bool, ansi: bool|null, no-interaction: bool, env: string|null}', $this->options());
        assertType('array{option: bool, optionWithValue: string|null, disable-event-dispatcher: bool, help: bool, silent: bool, quiet: bool, verbose: bool, version: bool, ansi: bool|null, no-interaction: bool, env: string|null}', $this->option());
        assertType('array{option: bool, optionWithValue: string|null, disable-event-dispatcher: bool, help: bool, silent: bool, quiet: bool, verbose: bool, version: bool, ansi: bool|null, no-interaction: bool, env: string|null}', $this->option(null));
        // Upstream reads shortcuts as options, but option() throws for them and accepts negations.
        assertType('mixed', $this->option('O'));
        assertType('bool', $this->option('option'));
        assertType('string|null', $this->option('optionWithValue'));
        assertType('bool', $this->option('help'));
        assertType('mixed', $this->option('v'));
        assertType('bool|null', $this->option('no-ansi'));

        assertType('false', $this->hasOption('O'));
        assertType('true', $this->hasOption('option'));
        assertType('true', $this->hasOption('optionWithValue'));
        assertType('true', $this->hasOption('help'));
        assertType('false', $this->hasOption('v'));
        assertType('true', $this->hasOption('no-ansi'));
        assertType('false', $this->hasOption('foobar'));
    }
}
