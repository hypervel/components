<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Console\Commands;

use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Mcp\Console\Commands\InspectorCommand;
use Hypervel\Mcp\Facades\Mcp;
use Hypervel\Mcp\Server\Registrar;
use Hypervel\Process\FakeProcessResult;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\Process;
use Hypervel\Support\Facades\URL;
use Hypervel\Tests\Mcp\Fixtures\ExampleServer;
use Hypervel\Tests\Mcp\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;

class McpInspectorCommandTest extends TestCase
{
    private Registrar&MockInterface $registrar;

    /**
     * Start with an empty MCP registry.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Mcp::swap(new Registrar);

        $this->registrar = m::mock(Registrar::class);
    }

    public function testNormalizesMixedPathsCorrectly(): void
    {
        $testCases = [
            'D:\Herd\cyborgfinance\artisan' => 'D:/Herd/cyborgfinance/artisan',
            '/var/www/hypervel/artisan' => '/var/www/hypervel/artisan',
            'C:\xampp\htdocs\project\artisan' => 'C:/xampp/htdocs/project/artisan',
            '/home/user/project/artisan' => '/home/user/project/artisan',
        ];

        foreach ($testCases as $input => $expected) {
            $normalized = str_replace('\\', '/', $input);
            $this->assertSame($expected, $normalized);
        }
    }

    public function testFailsWithInvalidHandle(): void
    {
        Mcp::local('demo', ExampleServer::class);
        Mcp::local('weather', ExampleServer::class);

        $this->artisan('mcp:inspector', ['handle' => 'invalid'])
            ->expectsOutputToContain('Starting the MCP Inspector for server [invalid].')
            ->expectsOutputToContain('MCP Server with name [invalid] not found.')
            ->assertExitCode(1);
    }

    public function testValidatesHandleArgumentIsRequired(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "handle")');
        $this->artisan('mcp:inspector')->run();
    }

    public function testFailsWhenNoServersAreRegistered(): void
    {
        $this->artisan('mcp:inspector', ['handle' => 'demo'])
            ->expectsOutputToContain('Starting the MCP Inspector for server [demo]')
            ->expectsOutputToContain('No MCP servers found. Please run `php artisan make:mcp-server [name]`')
            ->assertExitCode(1);
    }

    public function testHandlesNonStringHandleArgument(): void
    {
        $this->artisan('mcp:inspector', ['handle' => 123])
            ->expectsOutputToContain('Please pass a valid MCP server handle')
            ->assertExitCode(1);
    }

    public function testHandlesSingleServerWithUnknownType(): void
    {
        $unknownServer = new stdClass;

        $this->app->instance(Registrar::class, $this->registrar);

        $this->registrar->shouldReceive('getLocalServer')->once()->with('demo')->andReturn(null);

        $this->registrar->shouldReceive('getWebServer')->once()->with('demo')->andReturn(null);

        $this->registrar->shouldReceive('servers')->once()->andReturn(['single' => $unknownServer]);

        $this->artisan('mcp:inspector', ['handle' => 'demo'])
            ->expectsOutputToContain('MCP Server with name [demo] not found')
            ->assertExitCode(1);
    }

    public function testRetrievesPhpBinaryPathCorrectly(): void
    {
        $command = new InspectorCommand;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('phpBinary');

        $phpBinary = $method->invoke($command);

        // Should return either a path to php or 'php'
        $this->assertIsString($phpBinary);
        $this->assertGreaterThan(0, strlen($phpBinary));
    }

    public function testAsksForRouteParameterValuesWhenBuildingTheServerUrl(): void
    {
        $route = (new Registrar)->web('mcp/{organisation:uuid}', ExampleServer::class);

        $output = m::mock(OutputStyle::class, [new ArrayInput([]), new BufferedOutput])->makePartial();
        $output->shouldReceive('askQuestion')->once()
            ->with(m::on(fn (Question $question): bool => $question->getQuestion() === 'What is the value for the [organisation] route parameter?'))
            ->andReturn('4f8a1c2e');

        $components = new Factory($output);

        $command = new InspectorCommand;
        $command->setHypervel($this->app);

        (new ReflectionProperty($command, 'components'))->setValue($command, $components);

        $serverUrl = (new ReflectionMethod($command, 'serverUrl'))->invoke($command, $route);

        $this->assertSame('http://localhost/mcp/4f8a1c2e', $serverUrl);
    }

    public function testFailsWhenARouteParameterIsLeftBlank(): void
    {
        Mcp::web('mcp/{organisation:uuid}', ExampleServer::class);

        $this->artisan('mcp:inspector', ['handle' => 'demo'])
            ->expectsQuestion('What is the value for the [organisation] route parameter?', null)
            ->expectsOutputToContain('Every route parameter needs a value to inspect this server')
            ->assertExitCode(1);
    }

    public function testFailsWhenARouteParameterOnlyContainsWhitespace(): void
    {
        Mcp::web('mcp/{organisation:uuid}', ExampleServer::class);

        $this->artisan('mcp:inspector', ['handle' => 'demo'])
            ->expectsQuestion('What is the value for the [organisation] route parameter?', '   ')
            ->expectsOutputToContain('Every route parameter needs a value to inspect this server')
            ->assertExitCode(1);
    }

    public function testUsesTheSelectedLocalServerHandleAndDeletesItsConfiguration(): void
    {
        Mcp::local('weather', ExampleServer::class);
        $path = null;
        Process::fake(function (PendingProcess $process) use (&$path): FakeProcessResult {
            $path = $process->command[3];
            $config = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['weather'], array_keys($config['mcpServers']));
            $this->assertSame('stdio', $config['mcpServers']['weather']['type']);
            $this->assertSame([base_path('artisan'), 'mcp:start', 'weather'], $config['mcpServers']['weather']['args']);
            $this->assertNull($process->timeout);

            return Process::result();
        });

        $this->artisan('mcp:inspector', ['handle' => 'demo'])->assertExitCode(0)->run();

        $this->assertNotNull($path);
        $this->assertFileDoesNotExist($path);
    }

    public function testPreservesCertificateVerificationForHttpsServers(): void
    {
        URL::forceScheme('https');
        Mcp::web('weather', ExampleServer::class);
        Process::fake(function (PendingProcess $process): FakeProcessResult {
            $config = json_decode(file_get_contents($process->command[3]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('http', $config['mcpServers']['weather']['type']);
            $this->assertSame('https://localhost/weather', $config['mcpServers']['weather']['url']);
            $this->assertArrayNotHasKey('NODE_TLS_REJECT_UNAUTHORIZED', $process->environment);

            return Process::result();
        });

        $this->artisan('mcp:inspector', ['handle' => 'weather'])
            ->expectsOutputToContain('NODE_EXTRA_CA_CERTS')
            ->assertExitCode(0)->run();
    }

    public function testDeletesItsConfigurationAfterProcessFailure(): void
    {
        Mcp::local('weather', ExampleServer::class);
        $path = null;
        Process::fake(function (PendingProcess $process) use (&$path): FakeProcessResult {
            $path = $process->command[3];

            return Process::result(exitCode: 1);
        });

        $this->artisan('mcp:inspector', ['handle' => 'weather'])
            ->expectsOutputToContain('Failed to start MCP Inspector')
            ->assertExitCode(1)->run();

        $this->assertNotNull($path);
        $this->assertFileDoesNotExist($path);
    }

    public function testPreservesCallerCancellationAndDeletesItsConfiguration(): void
    {
        Mcp::local('weather', ExampleServer::class);
        $path = null;
        $cancellation = new CanceledException('Inspector stopped.');
        Process::fake(function (PendingProcess $process) use (&$path, $cancellation): never {
            $path = $process->command[3];
            throw $cancellation;
        });

        try {
            $this->artisan('mcp:inspector', ['handle' => 'weather'])->run();
            $this->fail('The caller cancellation must propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertNotNull($path);
        $this->assertFileDoesNotExist($path);
    }
}
