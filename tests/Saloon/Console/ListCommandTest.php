<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;

class ListCommandTest extends TestCase
{
    protected string $integrationsPath;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->integrationsPath = ParallelTesting::tempDir('SaloonListCommandTest');
        (new Filesystem)->deleteDirectory($this->integrationsPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->integrationsPath);

        parent::tearDown();
    }

    public function testMissingIntegrationDirectoryProducesAnEmptyList(): void
    {
        config()->set('saloon.integrations_path', $this->integrationsPath);

        $this->artisan('saloon:list')
            ->expectsOutputToContain('Integrations: 0')
            ->assertSuccessful();
    }

    public function testCommandListsIntegrationTypesAndResolvedRequestDetails(): void
    {
        config()->set('saloon.integrations_path', $this->integrationsPath);

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->integrationsPath . '/GitHub/Auth');
        $files->ensureDirectoryExists($this->integrationsPath . '/GitHub/Plugins');
        $files->ensureDirectoryExists($this->integrationsPath . '/GitHub/Requests/Users');
        $files->ensureDirectoryExists($this->integrationsPath . '/GitHub/Responses');
        $files->put($this->integrationsPath . '/GitHub/GitHubConnector.php', <<<'PHP'
<?php

class GitHubConnector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.github.com';
    }
}
PHP);
        $files->put($this->integrationsPath . '/GitHub/Auth/TokenAuthenticator.php', '<?php class TokenAuthenticator {}');
        $files->put($this->integrationsPath . '/GitHub/Plugins/SignsRequests.php', '<?php trait SignsRequests {}');
        $files->put($this->integrationsPath . '/GitHub/Requests/Users/GetUser.php', <<<'PHP'
<?php

class GetUser
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users/{$this->user}';
    }
}
PHP);
        $files->put($this->integrationsPath . '/GitHub/Responses/GitHubResponse.php', '<?php class GitHubResponse {}');

        $this->assertSame(0, Artisan::call('saloon:list'));

        $output = Artisan::output();

        $this->assertStringContainsString('Integrations: 1', $output);
        $this->assertStringContainsString('GitHub', $output);
        $this->assertStringContainsString('Authenticators: 1', $output);
        $this->assertStringContainsString('Connectors: 1', $output);
        $this->assertStringContainsString('Requests: 1', $output);
        $this->assertStringContainsString('Plugins: 1', $output);
        $this->assertStringContainsString('Responses: 1', $output);
        $this->assertStringContainsString('api.github.com', $output);
        $this->assertStringContainsString('/users/{user}', $output);
        $this->assertStringContainsString('GET', $output);
    }
}
