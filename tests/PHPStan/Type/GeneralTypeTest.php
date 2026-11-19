<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Type;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class GeneralTypeTest extends TypeInferenceTestCase
{
    #[DataProvider('dataFileAsserts')]
    public function testFileAsserts(
        string $assertType,
        string $file,
        mixed ...$args,
    ): void {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /** @return iterable<mixed> */
    public static function dataFileAsserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__ . '/data/abort.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/abstract-manager.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/app-make.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/application-make.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/arr-get-pull.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/arr-map.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/arrayable.php');
        // Upstream never registers artisan.php.
        yield from self::gatherAssertTypes(__DIR__ . '/data/artisan.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/auth.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/benchmark.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/belongs-to-many-pivot-accessor.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/builder-of-type.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-1346.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-1565.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-1718.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-1760.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-1819.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-1830.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-1985.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-1997.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-2057.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-2073.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-2111.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-2188.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/bug-2283.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collected-by-inheritance.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-of-type.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/concurrency-drivers.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/conditionable.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/console-commands.php');
        // REMOVED: container-array-access.php; container ArrayAccess is unsupported.
        yield from self::gatherAssertTypes(__DIR__ . '/data/container-make.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/contracts.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/custom-eloquent-builder.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/custom-eloquent-collection.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/custom-query-collections.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/database-transaction.php');
        // REMOVED: date-extension.php; DateExtension is omitted because the Date facade declares CarbonInterface.
        // Hypervel: covers the per-key eager-load, aggregate and morph map types.
        yield from self::gatherAssertTypes(__DIR__ . '/data/eager-load-maps.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/eager-loading-callbacks.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/eloquent-builder.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/eloquent-getter-types.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/eloquent-where.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/environment-helper.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/event-dispatcher.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/facade-fakes.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/facades.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/form-request.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/forwarded-references.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/gate-facade.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/has-events.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/helpers.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/higher-order-collection-proxies.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/higher-order-collection-proxy-methods.php');
        // Hypervel: covers the overrides that repeat method templates for PHPStan 2.3.5.
        yield from self::gatherAssertTypes(__DIR__ . '/data/inherited-method-templates.php');
        // REMOVED: logger.php; upstream never registers it, and Logger declares no getHandlers().
        yield from self::gatherAssertTypes(__DIR__ . '/data/model.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-date-column-casts.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-factories.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-methods.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-properties.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-properties-relations.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-relations.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-serialization.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-scopes.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-template-class-string.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-with-relations.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/managers.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/migrator.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/optional-helper.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/paginator-extension.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/passthru.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/pivot-types-in-collections.php');
        // REMOVED: PivotTypesInCollectionsTest.php; Hypervel always keeps pivot types, so the cases need no separate configuration.
        yield from self::gatherAssertTypes(__DIR__ . '/data/query-builder.php');
        // Upstream runs this per redisDriver setting; Hypervel only has phpredis, so it runs once here.
        yield from self::gatherAssertTypes(__DIR__ . '/data/redis-driver-phpredis.php');
        // REMOVED: RedisDriverNullTypeTest.php and RedisDriverPredisTypeTest.php; Hypervel only uses phpredis.
        yield from self::gatherAssertTypes(__DIR__ . '/data/relationship-query-callbacks.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/relation-of-type.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/request-header.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/request-object.php');
        // Upstream never registers request-user.php, which shares request-object.php's namespace.
        yield from self::gatherAssertTypes(__DIR__ . '/data/request-user.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/route.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/soft-deletes-static-builder.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/storage-disks.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/tappable.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/throw.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/translate.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/translate-concrete.php');
        // REMOVED: translate-contract.php; trans() returns the concrete translator, as on Laravel master.
        yield from self::gatherAssertTypes(__DIR__ . '/data/translator.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/use-eloquent-builder-inheritance.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/validator.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/view-exists.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/view.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/where-relation.php');

        // Console Commands
        yield from self::gatherAssertTypes(__DIR__ . '/../application/app/Console/Commands/BarCommand.php');
        // Upstream never registers BarCommandWithAttribute.php.
        yield from self::gatherAssertTypes(__DIR__ . '/../application/app/Console/Commands/BarCommandWithAttribute.php');
        yield from self::gatherAssertTypes(__DIR__ . '/../application/app/Console/Commands/BazCommand.php');
        yield from self::gatherAssertTypes(__DIR__ . '/../application/app/Console/Commands/FooCommand.php');
        yield from self::gatherAssertTypes(__DIR__ . '/../application/app/Console/Commands/RegisteredInKernelCommand.php');
        // Moved from upstream's LoadedCommands directory: Hypervel's kernel discovers app/Console/Commands.
        yield from self::gatherAssertTypes(__DIR__ . '/../application/app/Console/Commands/LoadedByKernelCommand.php');
        yield from self::gatherAssertTypes(__DIR__ . '/../Fixtures/Console/LookupCommand.php');
        yield from self::gatherAssertTypes(__DIR__ . '/../Fixtures/Console/WithArgumentCommand.php');
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
