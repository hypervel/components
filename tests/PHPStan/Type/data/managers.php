<?php

namespace Managers;

use Hypervel\Foundation\MaintenanceModeManager;
use Hypervel\Support\Facades\MaintenanceMode;
use Hypervel\Tests\PHPStan\Fixtures\Managers\BoundExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\CsvDriver;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ExtendedExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\GenericManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\JsonDriver;
use Hypervel\Tests\PHPStan\Fixtures\Managers\MacroableExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\RenamingManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ResolvedExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\SelectingManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\UnconfiguredManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\WrappedCreatorManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\WrappingManager;

use function PHPStan\Testing\assertType;

function test(ExportManager $manager): void
{
    // A manager forwards calls to its default driver, typed from its creator method's declared return.
    // The driver's own static returns stay the driver's.
    assertType('string', $manager->export([]));
    assertType('Hypervel\Tests\PHPStan\Fixtures\Managers\CsvDriver', $manager->withHeader());

    // The manager's @method tag wins over the driver's signature.
    assertType('int', $manager->count());

    // __call() runs in the manager, so it does not reach the driver's protected methods.
    assertType('*ERROR*', $manager->format());

    // The driver receives the argument's value, not the caller's variable.
    $buffer = 'rows';
    $manager->exportInto($buffer);
    assertType("'rows'", $buffer);
}

function creatorsAndOverrides(
    ExtendedExportManager $extended,
    WrappedCreatorManager $wrappedCreator,
    WrappingManager $wrapping,
    SelectingManager $selecting,
): void {
    // A registered creator replaces the creator method for the same driver.
    assertType('string', $extended->toJson());
    assertType('*ERROR*', $extended->export([]));

    // An override's declared return types the driver it selects or creates.
    assertType('string', $wrappedCreator->toJson());
    assertType('string', $wrapping->toJson());
    assertType('*ERROR*', $wrapping->export([]));
    assertType('string', $selecting->toJson());
}

function unforwardedCalls(
    MacroableExportManager $macroable,
    UnconfiguredManager $unconfigured,
    ResolvedExportManager $resolved,
    RenamingManager $renaming,
): void {
    // Macroable's __call() only runs macros.
    assertType('*ERROR*', $macroable->export([]));

    // Without a configured default driver, nothing is forwarded.
    assertType('*ERROR*', $unconfigured->export([]));

    // Manager::driver() keeps returning the CSV driver it created before the JSON creator was registered.
    assertType('*ERROR*', $resolved->toJson());

    // Analysis never calls an overriding getCreatorMethod(), so it cannot tell which creator runs.
    assertType('*ERROR*', $renaming->export([]));
}

function boundManagers(BoundExportManager $bound): void
{
    // The container binds the manager to a subclass, whose own selection and creator decide the driver.
    assertType('string', $bound->toJson());
    assertType('*ERROR*', $bound->export([]));
}

/**
 * @param GenericManager<CsvDriver> $csv
 * @param GenericManager<JsonDriver> $json
 */
function genericManagers(GenericManager $csv, GenericManager $json): void
{
    // Each specialization has its own declared driver.
    assertType('string', $csv->export([]));
    assertType('string', $json->toJson());
    assertType('*ERROR*', $json->export([]));
}

function maintenanceMode(MaintenanceModeManager $manager): void
{
    // Every maintenance driver implements the contract the manager declares as its mixin.
    assertType('bool', $manager->active());
    assertType('array', MaintenanceMode::data());
}
