<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\Support;

use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Configuration\ConfigMutationTracker;
use Hypervel\PHPStan\Support\ConfigSourceResolver;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\CallableCreationProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\CallbackCallableProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\DynamicCallbackWriteProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\DynamicDirectWriteProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\DynamicMergeKeyProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\EscapingCallbackProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\MergeCallableProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\ReadCallableCallbackProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\ReorderedArgumentsProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\UnpackedCallbackWriteProvider;
use Hypervel\Tests\PHPStan\Fixtures\Config\Writes\UnrecognizedFlowProvider;
use Hypervel\Tests\PHPStan\TestCase;
use Mockery as m;
use PHPStan\File\FileHelper;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function dirname;

class ConfigSourceResolverTest extends TestCase
{
    /**
     * Writes that would leave a key unbounded make every lookup unknown; flows the
     * static pass leaves to the tracker do not.
     *
     * @param class-string<ServiceProvider> $providerClass
     */
    #[DataProvider('providerWrites')]
    public function testUnknownWrites(string $providerClass, bool $unknownWrites): void
    {
        $this->assertSame($unknownWrites, $this->createResolver($providerClass)->hasUnknownWrites());
    }

    /**
     * @return array<string, array{class-string<ServiceProvider>, bool}>
     */
    public static function providerWrites(): array
    {
        return [
            'dynamic key in a callback' => [DynamicCallbackWriteProvider::class, true],
            'dynamic key written directly' => [DynamicDirectWriteProvider::class, true],
            'callback repository passed on' => [EscapingCallbackProvider::class, true],
            'unpacked arguments in a callback' => [UnpackedCallbackWriteProvider::class, true],
            'callable created in a callback' => [CallbackCallableProvider::class, true],
            'callable created from a merge method' => [MergeCallableProvider::class, true],
            'dynamic merge key' => [DynamicMergeKeyProvider::class, true],
            'unrelated container use and a local repository' => [UnrecognizedFlowProvider::class, false],
        ];
    }

    /**
     * Named arguments bind by name, creating a callable outside a callback writes nothing,
     * and a callable created from a read method only reads.
     *
     * @param class-string<ServiceProvider> $providerClass
     * @param list<string> $writtenKeys
     */
    #[DataProvider('providerWrittenKeys')]
    public function testWrittenKeys(string $providerClass, array $writtenKeys): void
    {
        $resolver = $this->createResolver($providerClass);

        $this->assertFalse($resolver->hasUnknownWrites());
        $this->assertSame($writtenKeys, $resolver->getWrittenKeys());
    }

    /**
     * @return array<string, array{class-string<ServiceProvider>, list<string>}>
     */
    public static function providerWrittenKeys(): array
    {
        return [
            'reordered arguments' => [ReorderedArgumentsProvider::class, ['package.callback', 'package.offset', 'package.helper', 'package.resolved', 'package.made']],
            'created callables' => [CallableCreationProvider::class, []],
            'read callable in a callback' => [ReadCallableCallbackProvider::class, ['package.copy']],
        ];
    }

    public function testCreatingCallableFromHelperDoesNotMakeItsMergeDefinite(): void
    {
        $merges = $this->createResolver(MergeCallableProvider::class)->getMerges();

        $this->assertCount(1, $merges);
        $this->assertSame('partial', $merges[0]['key']);
        $this->assertFalse($merges[0]['definite']);
    }

    public function testDynamicMergeKeyKeepsItsPackageConfigFile(): void
    {
        $resolver = $this->createResolver(DynamicMergeKeyProvider::class);

        $this->assertSame([], $resolver->getMerges());
        $this->assertTrue($resolver->isPackageConfigFile(dirname(__DIR__, 2) . '/Fixtures/Config/config/package.php'));
    }

    /**
     * Create a resolver whose application has loaded only the given provider.
     *
     * @param class-string<ServiceProvider> $providerClass
     */
    private function createResolver(string $providerClass): ConfigSourceResolver
    {
        $app = m::mock(Application::class);
        $provider = new $providerClass($app);

        $app->shouldReceive('getProviders')->with(ServiceProvider::class)->andReturn([$providerClass => $provider]);
        $app->shouldReceive('make')->with(ConfigMutationTracker::class)->andReturn(new ConfigMutationTracker);
        $app->shouldReceive('configPath')->andReturn(__DIR__ . '/missing-config');

        $container = new Container;
        $container->instance(Application::class, $app);

        $phpstan = PHPStanTestCase::getContainer();
        $resolver = new ConfigSourceResolver(
            $phpstan->getByType(FileHelper::class),
            $phpstan->getService('currentPhpVersionSimpleDirectParser'),
            PHPStanTestCase::createReflectionProvider(),
            [],
        );
        $resolver->setContainer($container);

        return $resolver;
    }
}
