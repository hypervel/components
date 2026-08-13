<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

class GeneratorCommandsTest extends TestCase
{
    /**
     * The generated files that the test owns.
     *
     * @var list<string>
     */
    protected array $generatedFiles = [];

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;

        foreach ($this->generatedFiles as $generatedFile) {
            $files->delete($generatedFile);
        }

        parent::tearDown();
    }

    public function testConnectorIsGeneratedInTheDefaultIntegrationDirectory(): void
    {
        $this->artisan('saloon:connector', [
            'integration' => 'GitHub',
            'name' => 'GitHubConnector',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $path = app_path('Http/Integrations/GitHub/GitHubConnector.php');
        $contents = $this->generatedFile($path);

        $this->assertStringContainsString('namespace App\Http\Integrations\GitHub;', $contents);
        $this->assertStringContainsString('use Hypervel\Saloon\Http\Connector;', $contents);
        $this->assertStringContainsString('declare(strict_types=1);', $contents);
    }

    public function testOAuthConnectorUsesTheImmutableOAuthStub(): void
    {
        $this->artisan('saloon:connector', [
            'integration' => 'GitHub',
            'name' => 'GitHubConnector',
            '--oauth' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $contents = $this->generatedFile(app_path('Http/Integrations/GitHub/GitHubConnector.php'));

        $this->assertStringContainsString('use Hypervel\Saloon\Data\OAuthConfig;', $contents);
        $this->assertStringContainsString('use AuthorizationCodeGrant;', $contents);
        $this->assertStringContainsString('return new OAuthConfig(', $contents);
        $this->assertStringNotContainsString('->setClientId(', $contents);
    }

    public function testNestedRequestUsesTheSelectedMethod(): void
    {
        $this->artisan('saloon:request', [
            'integration' => 'GitHub',
            'name' => 'Users/GetUser',
            '--method' => 'query',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $path = app_path('Http/Integrations/GitHub/Requests/Users/GetUser.php');
        $contents = $this->generatedFile($path);

        $this->assertStringContainsString('namespace App\Http\Integrations\GitHub\Requests\Users;', $contents);
        $this->assertStringContainsString('protected Method $method = Method::QUERY;', $contents);
    }

    public function testUnsupportedRequestMethodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The method [BREW] is not supported.');

        $this->artisan('saloon:request', [
            'integration' => 'Coffee',
            'name' => 'BrewCoffee',
            '--method' => 'BREW',
            '--no-interaction' => true,
        ]);
    }

    public function testAllSupportingTypesCanBeGenerated(): void
    {
        $commands = [
            'saloon:auth' => ['Auth', 'ApiKeyAuthenticator', 'Auth/ApiKeyAuthenticator.php'],
            'saloon:plugin' => ['Plugins', 'SignsRequests', 'Plugins/SignsRequests.php'],
            'saloon:response' => ['Responses', 'GitHubResponse', 'Responses/GitHubResponse.php'],
        ];

        foreach ($commands as $command => [$directory, $name, $relativePath]) {
            $this->artisan($command, [
                'integration' => 'GitHub',
                'name' => $name,
                '--no-interaction' => true,
            ])->assertSuccessful();

            $contents = $this->generatedFile(app_path('Http/Integrations/GitHub/' . $relativePath));

            $this->assertStringContainsString("namespace App\\Http\\Integrations\\GitHub\\{$directory};", $contents);
        }
    }

    public function testConfiguredPathAndNamespaceAreIndependentDefaults(): void
    {
        config()->set('saloon.integrations_path', base_path('domains/integrations'));
        config()->set('saloon.integrations_namespace', 'Domain\Integrations');

        $this->artisan('saloon:connector', [
            'integration' => 'Stripe',
            'name' => 'StripeConnector',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $path = base_path('domains/integrations/Stripe/StripeConnector.php');
        $contents = $this->generatedFile($path);

        $this->assertStringContainsString('namespace Domain\Integrations\Stripe;', $contents);
    }

    public function testCommandOptionsOverrideConfiguredPathAndNamespace(): void
    {
        config()->set('saloon.integrations_path', base_path('ignored/integrations'));
        config()->set('saloon.integrations_namespace', 'Ignored\Integrations');

        $targetPath = base_path('generated');

        $this->artisan('saloon:response', [
            'integration' => 'Stripe',
            'name' => 'StripeResponse',
            '--target-path' => $targetPath,
            '--target-namespace' => 'Domain\Responses',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $contents = $this->generatedFile($targetPath . '/StripeResponse.php');

        $this->assertStringContainsString('namespace Domain\Responses;', $contents);
    }

    public function testTargetNamespaceDoesNotChangeTheConfiguredPath(): void
    {
        config()->set('saloon.integrations_path', base_path('domains/integrations'));

        $this->artisan('saloon:request', [
            'integration' => 'Stripe',
            'name' => 'Payments/CreatePayment',
            '--method' => 'POST',
            '--target-namespace' => 'Domain\StripeRequests',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $path = base_path('domains/integrations/Stripe/Requests/Payments/CreatePayment.php');
        $contents = $this->generatedFile($path);

        $this->assertStringContainsString('namespace Domain\StripeRequests\Payments;', $contents);
    }

    public function testPublishedStubOverridesThePackageStub(): void
    {
        $stubPath = base_path('stubs/saloon.response.stub');
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

        $this->artisan('saloon:response', [
            'integration' => 'Stripe',
            'name' => 'StripeResponse',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $contents = $this->generatedFile(app_path('Http/Integrations/Stripe/Responses/StripeResponse.php'));

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
