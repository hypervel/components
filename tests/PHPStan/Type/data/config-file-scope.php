<?php

namespace ConfigFileScope;

use function PHPStan\Testing\assertType;

function callerWithSameVariable(): void
{
    $hosts = 123;

    assertType('non-empty-list<string>', config('scopevars.hosts'));
}

function callerWithoutVariable(): void
{
    assertType('non-empty-list<string>', config('scopevars.hosts'));
}

function phpDocUsesConfigFileImports(): void
{
    assertType('Hypervel\Support\Collection<int, string>', config('scopedoc.items'));
}

function returnInsideClosureIsNotTheConfig(): void
{
    assertType('lowercase-string', config('scopeclosure.v'));
}

function conditionalReturnHasNoSingleValue(): void
{
    assertType('mixed', config('scopeconditional.v'));
}

function variableFromIncludeIsUnknown(): void
{
    assertType('mixed', config('scopeinclude.v'));
}

// Unlike upstream, a config file reads the configuration as it stands while the file loads:
// a file's own value is set only after it returns, and later files are not loaded yet.
function selfReference(): void
{
    assertType("'larastan'", config('scopeself.name'));
    assertType('null', config('scopeself.early'));
    assertType('null', config('scopeself.late'));
}

// A closure in a config file may run while the file loads or after, so its reads keep the declared type.
function closureInConfigFile(): void
{
    assertType('Closure(): mixed', config('scopeself.callback'));
}

function readsOtherConfigFile(): void
{
    assertType("'leaf'", config('scopereader.v'));
    assertType("'leaf'", config('scopereader.inline'));
}

function readsLaterConfigFile(): void
{
    assertType('null', config('scopeforward.v'));
}
