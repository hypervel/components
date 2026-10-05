<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class GeneratorCommandsTest extends TestCase
{
    /**
     * The generated files that the test owns.
     *
     * @var list<string>
     */
    protected array $generatedFiles = [];

    /**
     * The generated directories that the test owns.
     *
     * @var list<string>
     */
    protected array $generatedDirectories = [];

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $files = new Filesystem;

        foreach ($this->generatedFiles as $generatedFile) {
            $files->delete($generatedFile);
        }

        foreach ($this->generatedDirectories as $generatedDirectory) {
            $files->deleteDirectory($generatedDirectory);
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

    public function testMissingArgumentsArePromptedWithExistingIntegrations(): void
    {
        $integrationsPath = ParallelTesting::tempDir('SaloonGeneratorCommandsTest');
        $files = new Filesystem;
        $files->deleteDirectory($integrationsPath);
        $files->ensureDirectoryExists($integrationsPath . '/GitHub');
        $files->ensureDirectoryExists($integrationsPath . '/Stripe');
        $this->generatedDirectories[] = $integrationsPath;
        config()->set('saloon.integrations_path', $integrationsPath);
        config()->set('saloon.integrations_namespace', 'Domain\Integrations');

        $this->artisan('saloon:request')
            ->expectsChoice('What is the related integration?', 'Stripe', ['GitHub', 'Stripe'])
            ->expectsQuestion('What should the Saloon request be named?', 'CreatePayment')
            ->expectsChoice(
                'What method should the Saloon request send?',
                'POST',
                ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE', 'QUERY'],
            )
            ->assertSuccessful();

        $contents = $this->generatedFile($integrationsPath . '/Stripe/Requests/CreatePayment.php');

        $this->assertStringContainsString('namespace Domain\Integrations\Stripe\Requests;', $contents);
        $this->assertStringContainsString('protected Method $method = Method::POST;', $contents);
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

    #[DataProvider('integrationsPathsInsideTheAppDirectory')]
    public function testAnIntegrationsPathInsideTheAppDirectoryDeterminesTheNamespace(string $relativePath, string $namespace): void
    {
        $integrationsPath = app_path($relativePath);
        config()->set('saloon.integrations_path', $integrationsPath);
        $this->generatedDirectories[] = $integrationsPath . '/Stripe';

        $this->artisan('saloon:connector', [
            'integration' => 'Stripe',
            'name' => 'StripeConnector',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $contents = $this->generatedFile($integrationsPath . '/Stripe/StripeConnector.php');

        $this->assertStringContainsString("namespace {$namespace};", $contents);
    }

    /**
     * Get integrations paths inside the app directory and their generated namespaces.
     *
     * @return array<string, array{string, string}>
     */
    public static function integrationsPathsInsideTheAppDirectory(): array
    {
        return [
            'application directory' => ['', 'App\Stripe'],
            'subdirectory' => ['Integrations', 'App\Integrations\Stripe'],
        ];
    }

    public function testAnIntegrationsPathOutsideTheAppDirectoryRequiresANamespace(): void
    {
        // A sibling whose name starts with the app directory's name is still outside it.
        config()->set('saloon.integrations_path', base_path('app-other/Integrations'));
        $this->generatedDirectories[] = base_path('app-other');

        try {
            $this->artisan('saloon:connector', [
                'integration' => 'Stripe',
                'name' => 'StripeConnector',
                '--no-interaction' => true,
            ])->run();
            $this->fail('The missing integrations namespace was not rejected.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'The [saloon.integrations_namespace] configuration value is required when the integrations path is outside the application directory.',
                $exception->getMessage(),
            );
        }

        $this->assertDirectoryDoesNotExist(base_path('app-other'));
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
