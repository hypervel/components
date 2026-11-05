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
        yield from self::gatherAssertTypes(__DIR__ . '/data/conditionable.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/console-commands.php');
        // REMOVED: container-array-access.php; container ArrayAccess is unsupported.
        yield from self::gatherAssertTypes(__DIR__ . '/data/container-make.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/contracts.php');
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
        yield from self::gatherAssertTypes(__DIR__ . '/data/managers.php');
        yield from self::gatherAssertTypes(__DIR__ . '/data/optional-helper.php');
        // Upstream runs this per redisDriver setting; Hypervel only has phpredis, so it runs once here.
        yield from self::gatherAssertTypes(__DIR__ . '/data/redis-driver-phpredis.php');
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
