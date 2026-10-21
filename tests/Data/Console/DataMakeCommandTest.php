<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Process\Process;

class DataMakeCommandTest extends TestCase
{
    /**
     * The generated files that the test owns.
     *
     * @var list<string>
     */
    protected array $generatedFiles = [];

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [DataServiceProvider::class];
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;

        foreach ($this->generatedFiles as $generatedFile) {
            $files->delete($generatedFile);
        }

        parent::tearDown();
    }

    public function testDataIsGeneratedWithTheConfiguredSuffix(): void
    {
        $this->artisan('make:data', [
            'name' => 'User',
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->artisan('make:data', [
            'name' => 'TeamData',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $contents = $this->generatedFile(app_path('Data/UserData.php'));
        $suffixed = $this->generatedFile(app_path('Data/TeamData.php'));

        $this->assertStringContainsString('namespace App\Data;', $contents);
        $this->assertStringContainsString('use Hypervel\Data\Data;', $contents);
        $this->assertStringContainsString('class UserData extends Data', $contents);
        $this->assertStringContainsString('declare(strict_types=1);', $contents);
        $this->assertStringContainsString('class TeamData extends Data', $suffixed);
    }

    public function testNamespaceAndSuffixOptionsOverrideTheConfiguredDefaults(): void
    {
        $this->artisan('make:data', [
            'name' => 'Invoice',
            '--target-namespace' => 'App\Transfer',
            '--suffix' => 'Dto',
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->artisan('make:data', [
            'name' => 'Receipt',
            '--suffix' => '',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $custom = $this->generatedFile(app_path('Transfer/InvoiceDto.php'));
        $unsuffixed = $this->generatedFile(app_path('Data/Receipt.php'));

        $this->assertStringContainsString('namespace App\Transfer;', $custom);
        $this->assertStringContainsString('class InvoiceDto extends Data', $custom);
        $this->assertStringContainsString('class Receipt extends Data', $unsuffixed);
    }

    #[DefineEnvironment('withoutConfiguredSuffix')]
    public function testConfiguredDefaultsApplyWhenNoOptionIsGiven(): void
    {
        $this->artisan('make:data', [
            'name' => 'Profile',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $contents = $this->generatedFile(app_path('Dto/Profile.php'));

        $this->assertStringContainsString('namespace App\Dto;', $contents);
        $this->assertStringContainsString('class Profile extends Data', $contents);
    }

    /**
     * Configure an unsuffixed generator namespace.
     */
    protected function withoutConfiguredSuffix(ApplicationContract $app): void
    {
        $app->make('config')->set('data.commands.make', ['namespace' => 'Dto', 'suffix' => '']);
    }

    public function testNestedAndQualifiedNamesUseTheirDeclaredNamespaces(): void
    {
        $this->artisan('make:data', [
            'name' => 'Billing/InvoiceData',
            '--no-interaction' => true,
        ])->assertSuccessful();
        $this->artisan('make:data', [
            'name' => 'App\Domain\ReportData',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $nested = $this->generatedFile(app_path('Data/Billing/InvoiceData.php'));
        $qualified = $this->generatedFile(app_path('Domain/ReportData.php'));

        $this->assertStringContainsString('namespace App\Data\Billing;', $nested);
        $this->assertStringContainsString('namespace App\Domain;', $qualified);
    }

    public function testExistingDataRequiresForceToBeReplaced(): void
    {
        $arguments = [
            'name' => 'ExistingData',
            '--no-interaction' => true,
        ];
        $path = app_path('Data/ExistingData.php');

        $this->artisan('make:data', $arguments)->assertSuccessful();
        $this->generatedFiles[] = $path;
        (new Filesystem)->put($path, 'sentinel');

        $this->artisan('make:data', $arguments)
            ->expectsOutputToContain('Data already exists.');
        $this->assertSame('sentinel', (new Filesystem)->get($path));

        $this->artisan('make:data', [
            ...$arguments,
            '--force' => true,
        ])->assertSuccessful();

        $contents = $this->generatedFile($path);

        $this->assertStringNotContainsString('sentinel', $contents);
        $this->assertStringContainsString('class ExistingData extends Data', $contents);
    }

    public function testApplicationStubOverridesThePackageStub(): void
    {
        $stubPath = base_path('stubs/data.stub');
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($stubPath));
        $files->put($stubPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace {{ namespace }};

class {{ class }}
{
    public const string SOURCE = 'published';
}
PHP);
        $this->generatedFiles[] = $stubPath;

        $this->artisan('make:data', [
            'name' => 'PublishedData',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $contents = $this->generatedFile(app_path('Data/PublishedData.php'));

        $this->assertStringContainsString("public const string SOURCE = 'published';", $contents);
    }

    /**
     * Read and validate a generated PHP file.
     */
    protected function generatedFile(string $path): string
    {
        $this->generatedFiles[] = $path;

        $process = new Process([PHP_BINARY, '-l', $path]);
        $process->mustRun();

        return (new Filesystem)->get($path);
    }
}
