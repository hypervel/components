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
        yield from self::gatherAssertTypes(__DIR__ . '/data/builder-of-type.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/collection-of-type.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/conditionable.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/console-commands.php');
        // REMOVED: container-array-access.php; container ArrayAccess is unsupported.
        yield from self::gatherAssertTypes(__DIR__ . '/data/container-make.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/contracts.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/custom-eloquent-builder.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/custom-eloquent-collection.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/custom-query-collections.php');
        // Hypervel: covers the per-key eager-load, aggregate and morph map types.
        yield from self::gatherAssertTypes(__DIR__ . '/data/eager-load-maps.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/eager-loading-callbacks.php');
        // REMOVED: eloquent-builder.php; it asserts Laravel releases before 11.42.
        yield from self::gatherAssertTypes(__DIR__ . '/data/eloquent-builder-l11-42.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/eloquent-builder-l12-19.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/eloquent-builder-l13-31.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/eloquent-where.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/environment-helper.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/facade-fakes.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/facades.php');
        // REMOVED: facades-l11-42.php; it asserts Laravel 11's Redis facade docblock types.
        yield from self::gatherAssertTypes(__DIR__ . '/data/facades-l12-20.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/facades-l12-41.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/form-request.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/gate-facade.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/helpers.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/higher-order-collection-proxies.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/higher-order-collection-proxy-methods.php');
        // Hypervel: covers the overrides that repeat method templates for PHPStan 2.3.5.
        yield from self::gatherAssertTypes(__DIR__ . '/data/inherited-method-templates.php');
        // REMOVED: model.php; it asserts Laravel releases before 11.42.
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-l11-42.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-collections-l11-28.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-collections-l13-4.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-date-column-casts.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-increment-each-l13-3.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-increment-each-l13-20.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-methods.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-properties.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-properties-relations.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-properties-l13.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-properties-l13-2.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-relations.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-serialization.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-serialization-l13.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-scope-attribute-l12.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-scopes.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-template-class-string.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/model-with-relations.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/managers.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/optional-helper.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/paginator-extension.php');
        // REMOVED: passthru.php; it asserts Laravel releases before 12.15.
        yield from self::gatherAssertTypes(__DIR__ . '/data/passthru-l12-15.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/query-builder.php');
        // Upstream runs this per redisDriver setting; Hypervel only has phpredis, so it runs once here.
        yield from self::gatherAssertTypes(__DIR__ . '/data/redis-driver-phpredis.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/relationship-query-callbacks.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/relation-of-type.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/request-header.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/request-object.php');
        // Upstream never registers request-user.php, which shares request-object.php's namespace.
        yield from self::gatherAssertTypes(__DIR__ . '/data/request-user.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/storage-disks.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/tappable.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/throw.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/translate.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/translator.php');
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
