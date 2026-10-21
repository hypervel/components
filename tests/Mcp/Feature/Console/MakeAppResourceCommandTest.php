<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class MakeAppResourceCommandTest extends TestCase
{
    public function testCanCreateAnAppResourceClass(): void
    {
        $response = $this->artisan('make:mcp-app-resource', [
            'name' => 'TestAppResource',
        ]);

        $response->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Mcp/Resources/TestAppResource.php'));
    }

    public function testCreatesABladeViewAlongsideThePhpClass(): void
    {
        $this->artisan('make:mcp-app-resource', [
            'name' => 'DashboardResource',
        ])->assertExitCode(0)->run();

        $this->assertFileExists(app_path('Mcp/Resources/DashboardResource.php'));
        $this->assertFileExists(resource_path('views/mcp/dashboard-resource.blade.php'));
    }

    public function testDoesNotGenerateAJsEntryFile(): void
    {
        $this->artisan('make:mcp-app-resource', [
            'name' => 'DashboardResource',
        ])->assertExitCode(0)->run();

        $this->assertFileDoesNotExist(resource_path('js/mcp/dashboard-resource.js'));
    }

    public function testGeneratesPhpClassThatExtendsAppResource(): void
    {
        $this->artisan('make:mcp-app-resource', [
            'name' => 'DashboardResource',
        ])->assertExitCode(0)->run();

        $content = file_get_contents(app_path('Mcp/Resources/DashboardResource.php'));

        $this->assertStringContainsString('extends AppResource', $content);
    }

    public function testGeneratesBladeViewWithMcpAppComponent(): void
    {
        $this->artisan('make:mcp-app-resource', [
            'name' => 'DashboardResource',
        ])->assertExitCode(0)->run();

        $content = file_get_contents(resource_path('views/mcp/dashboard-resource.blade.php'));

        $this->assertStringContainsString('<x-mcp::app', $content);
    }

    public function testGeneratesBladeViewWithCreateMcpAppInlineScript(): void
    {
        $this->artisan('make:mcp-app-resource', [
            'name' => 'DashboardResource',
        ])->assertExitCode(0)->run();

        $content = file_get_contents(resource_path('views/mcp/dashboard-resource.blade.php'));

        $this->assertStringContainsString('createMcpApp', $content);
        $this->assertStringNotContainsString('entry=', $content);
    }

    public function testMayPublishCustomStubs(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'mcp-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/mcp-app-resource.stub'));
        $this->assertFileExists(base_path('stubs/mcp-app-resource.view.stub'));
    }

    #[TestWith(['Admin/DashboardApp', false])]
    #[TestWith(['Admin\DashboardApp', false])]
    #[TestWith(['Admin\DashboardApp', true])]
    public function testPreservesNamespaceSegmentsInViewPaths(string $name, bool $customViewPath): void
    {
        $viewPath = resource_path($customViewPath ? 'custom-views' : 'views');

        if ($customViewPath) {
            config(['view.paths' => [$viewPath]]);
        }

        try {
            $this->artisan('make:mcp-app-resource', ['name' => $name])->assertExitCode(0)->run();

            $this->assertFileExists(app_path('Mcp/Resources/Admin/DashboardApp.php'));
            $this->assertFileExists($viewPath . '/mcp/admin/dashboard-app.blade.php');

            $content = file_get_contents(app_path('Mcp/Resources/Admin/DashboardApp.php'));

            $this->assertStringContainsString("Response::view('mcp.admin.dashboard-app'", $content);
        } finally {
            if ($customViewPath) {
                (new Filesystem)->deleteDirectory($viewPath);
            }
        }
    }

    public function testGeneratesUniqueViewsForDifferentNamespacesWithSameClassName(): void
    {
        $this->artisan('make:mcp-app-resource', [
            'name' => 'Admin/DashboardApp',
        ])->assertExitCode(0)->run();

        $this->artisan('make:mcp-app-resource', [
            'name' => 'Reports/DashboardApp',
        ])->assertExitCode(0)->run();

        $this->assertFileExists(resource_path('views/mcp/admin/dashboard-app.blade.php'));
        $this->assertFileExists(resource_path('views/mcp/reports/dashboard-app.blade.php'));
    }

    public function testGeneratesStubWithoutUnusedImports(): void
    {
        $this->artisan('make:mcp-app-resource', [
            'name' => 'CleanResource',
        ])->assertExitCode(0)->run();

        $content = file_get_contents(app_path('Mcp/Resources/CleanResource.php'));

        $this->assertStringNotContainsString('use Hypervel\Mcp\Server\Ui\Enums\Permission', $content);
    }

    public function testForwardsTitleToTheAppComponentInGeneratedView(): void
    {
        $this->artisan('make:mcp-app-resource', [
            'name' => 'TitledResource',
        ])->assertExitCode(0)->run();

        $content = file_get_contents(resource_path('views/mcp/titled-resource.blade.php'));

        $this->assertStringContainsString('<x-mcp::app :title="$title">', $content);
    }

    public function testRespectsForceFlagForExistingFiles(): void
    {
        $this->artisan('make:mcp-app-resource', [
            'name' => 'DashboardResource',
        ])->assertExitCode(0)->run();

        $this->artisan('make:mcp-app-resource', [
            'name' => 'DashboardResource',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(resource_path('views/mcp/dashboard-resource.blade.php'));
    }
}
