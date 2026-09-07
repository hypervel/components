<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\PHPUnit\TestCase;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Testbench\Fixtures\Providers\ChildServiceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ConfigTest extends TestCase
{
    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = ParallelTesting::tempDir('TestbenchConfigTest');

        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->temporaryDirectory);
        $filesystem->makeDirectory($this->temporaryDirectory, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    #[Test]
    public function itCanLoadConfigurationFile(): void
    {
        $config = Config::loadFromYaml(__DIR__ . '/Fixtures/');

        $this->assertNull($config['hypervel']);
        $this->assertSame(['APP_DEBUG=(false)'], $config['env']);
        $this->assertSame([], $config['bootstrappers']);
        $this->assertSame([TestbenchServiceProvider::class], $config['providers']);
        $this->assertSame([], $config['dont-discover']);
        $this->assertSame([], $config['migrations']);
        $this->assertFalse($config['seeders']);

        $this->assertSame([
            'env' => [
                'APP_DEBUG=(false)',
            ],
            'bootstrappers' => [],
            'providers' => [
                TestbenchServiceProvider::class,
            ],
            'dont-discover' => [],
        ], $config->getExtraAttributes());

        $this->assertSame([
            'directories' => [],
            'files' => [],
        ], $config->getPurgeAttributes());

        $this->assertSame([
            'install' => true,
            'auth' => false,
            'health' => null,
            'sync' => [],
            'discovers' => [
                'config' => false,
                'factories' => false,
                'web' => false,
                'api' => false,
                'commands' => false,
                'components' => false,
                'views' => false,
            ],
        ], $config->getWorkbenchAttributes());

        $this->assertSame([
            'config' => false,
            'factories' => false,
            'web' => false,
            'api' => false,
            'commands' => false,
            'components' => false,
            'views' => false,
        ], $config->getWorkbenchDiscoversAttributes());
    }

    #[Test]
    public function itCanLoadDefaultConfiguration(): void
    {
        $config = new Config;

        $this->assertNull($config['hypervel']);
        $this->assertSame([], $config['env']);
        $this->assertSame([], $config['bootstrappers']);
        $this->assertSame([], $config['providers']);
        $this->assertSame([], $config['dont-discover']);
        $this->assertSame([], $config['migrations']);
        $this->assertFalse($config['seeders']);

        $this->assertSame([
            'env' => [],
            'bootstrappers' => [],
            'providers' => [],
            'dont-discover' => [],
        ], $config->getExtraAttributes());

        $this->assertSame([
            'directories' => [],
            'files' => [],
        ], $config->getPurgeAttributes());

        $this->assertSame([
            'install' => true,
            'auth' => false,
            'health' => null,
            'sync' => [],
            'discovers' => [
                'config' => false,
                'factories' => false,
                'web' => false,
                'api' => false,
                'commands' => false,
                'components' => false,
                'views' => false,
            ],
        ], $config->getWorkbenchAttributes());

        $this->assertSame([
            'config' => false,
            'factories' => false,
            'web' => false,
            'api' => false,
            'commands' => false,
            'components' => false,
            'views' => false,
        ], $config->getWorkbenchDiscoversAttributes());
    }

    #[Test]
    public function itCanAddAdditionalProvidersToConfigurationFile(): void
    {
        $config = Config::loadFromYaml(__DIR__ . '/Fixtures/');

        $this->assertSame([
            TestbenchServiceProvider::class,
        ], $config['providers']);

        $config->addProviders([
            ChildServiceProvider::class,
        ]);

        $this->assertSame([
            TestbenchServiceProvider::class,
            ChildServiceProvider::class,
        ], $config['providers']);
    }

    #[Test]
    public function itCantAddDuplicatedProvidersToConfigurationFile(): void
    {
        $config = Config::loadFromYaml(__DIR__ . '/Fixtures/');

        $this->assertSame([
            TestbenchServiceProvider::class,
        ], $config['providers']);

        $config->addProviders([
            TestbenchServiceProvider::class,
        ]);

        $this->assertSame([
            TestbenchServiceProvider::class,
        ], $config['providers']);
    }

    #[Test]
    public function itUsesSuppliedDefaultsForAnEmptyYamlDocument(): void
    {
        file_put_contents($this->temporaryDirectory . '/testbench.yaml', '');

        $config = Config::loadFromYaml($this->temporaryDirectory, defaults: [
            'providers' => [TestbenchServiceProvider::class],
        ]);

        $this->assertSame([TestbenchServiceProvider::class], $config['providers']);
    }

    #[Test]
    public function itNormalizesNullableDocumentedMappings(): void
    {
        file_put_contents(
            $this->temporaryDirectory . '/testbench.yaml',
            "purge: null\nworkbench: null\n",
        );

        $config = Config::loadFromYaml($this->temporaryDirectory);

        $this->assertSame([], $config['purge']);
        $this->assertSame([], $config['workbench']);
    }

    #[Test]
    #[DataProvider('invalidYamlRoots')]
    public function itRejectsANonMappingYamlRoot(string $yaml): void
    {
        file_put_contents($this->temporaryDirectory . '/testbench.yaml', $yaml);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Testbench configuration root must be a mapping.');

        Config::loadFromYaml($this->temporaryDirectory);
    }

    /**
     * Provide invalid YAML document roots.
     */
    public static function invalidYamlRoots(): array
    {
        return [
            'scalar' => ['invalid'],
            'non-empty list' => ["- first\n- second\n"],
        ];
    }

    #[Test]
    public function itRejectsANonMappingDocumentedSection(): void
    {
        file_put_contents($this->temporaryDirectory . '/testbench.yaml', "purge: invalid\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Testbench [purge] configuration must be a mapping.');

        Config::loadFromYaml($this->temporaryDirectory);
    }
}
