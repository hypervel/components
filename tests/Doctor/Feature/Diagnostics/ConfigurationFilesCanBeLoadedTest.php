<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\ConfigurationFilesCanBeLoaded;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConfigurationFilesCanBeLoadedTest extends TestCase
{
    private string $directory;

    /**
     * Create an isolated, relocated configuration directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = ParallelTesting::tempDir('DoctorConfigurationFiles');
        File::deleteDirectory($this->directory);
        File::makeDirectory($this->directory . '/nested', 0775, true);
        $this->app->useConfigPath($this->directory);
    }

    /**
     * Remove the configuration fixture.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->directory);
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('configurationFiles')]
    public function testReportsConfigurationFilesThatCannotLoad(string $file): void
    {
        file_put_contents($this->directory . '/' . $file, "<?php\n\nthrow new RuntimeException('broken config');\n");

        $result = (new ConfigurationFilesCanBeLoaded)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame($file . ': broken config', $result->details);
        $this->assertEquals([Link::docs('configuration')], $result->links);
    }

    /**
     * Provide flat and nested configuration files.
     */
    public static function configurationFiles(): array
    {
        return [['broken.php'], ['nested/broken.php']];
    }

    public function testSkipsAnEmptyConfigurationDirectoryAndLoadsNestedFiles(): void
    {
        $this->assertSame('skip', (new ConfigurationFilesCanBeLoaded)->check()->status->value);

        file_put_contents($this->directory . '/nested/valid.php', '<?php return ["key" => "value"];');

        $this->assertSame('pass', (new ConfigurationFilesCanBeLoaded)->check()->status->value);
    }
}
