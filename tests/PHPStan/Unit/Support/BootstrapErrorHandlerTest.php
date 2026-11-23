<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\Support;

use Exception;
use Hypervel\PHPStan\Support\BootstrapErrorHandler;
use Hypervel\Tests\PHPStan\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Output\BufferedOutput;

use function getcwd;
use function str_replace;

class BootstrapErrorHandlerTest extends TestCase
{
    public function testHandleUserCodeError(): void
    {
        $output = new BufferedOutput;
        $handler = new BootstrapErrorHandler($output);

        $exception = new Exception('User code error');

        $reflection = new ReflectionClass($exception);
        $fileProperty = $reflection->getProperty('file');
        $fileProperty->setValue($exception, getcwd() . '/app/Providers/AppServiceProvider.php');

        $handler->handle($exception);

        $result = $output->fetch();

        $this->assertStringContainsString('Application bootstrap failed', $result);
        $this->assertStringContainsString('PHPStan was unable to bootstrap your application.', $result);
        $this->assertStringContainsString('Check your environment variables in the .env file', $result);
        $this->assertStringContainsString('Error: User code error', $result);
        $this->assertStringContainsString('Stack trace:', $result);
    }

    public function testHandleFrameworkError(): void
    {
        $output = new BufferedOutput;
        $handler = new BootstrapErrorHandler($output);

        $exception = new Exception('Framework error');

        $reflection = new ReflectionClass($exception);
        $fileProperty = $reflection->getProperty('file');
        $fileProperty->setValue($exception, getcwd() . '/vendor/hypervel/foundation/src/Application.php');

        $handler->handle($exception);

        $result = $output->fetch();

        // Upstream blames the framework for failures under /vendor/; Hypervel gives
        // every failure the same guidance.
        $this->assertStringContainsString('Application bootstrap failed', $result);
        $this->assertStringContainsString('PHPStan was unable to bootstrap your application.', $result);
        $this->assertStringContainsString('Check your environment variables in the .env file', $result);
        $this->assertStringContainsString('Error: Framework error', $result);
        $this->assertStringContainsString('Stack trace:', $result);
    }

    public function testHandleTreatsErrorTextAsLiteral(): void
    {
        $output = new BufferedOutput;
        $handler = new BootstrapErrorHandler($output, decorated: false);

        $handler->handle(new Exception('Missing <info>config</info> value'));

        $this->assertStringContainsString('Error: Missing <info>config</info> value', $output->fetch());
    }

    public function testStackTraceUsesRelativePaths(): void
    {
        $output = new BufferedOutput;
        $handler = new BootstrapErrorHandler($output);

        $exception = new Exception('Path error');

        $handler->handle($exception);

        $result = $output->fetch();

        $projectRoot = getcwd();
        // The stack trace should not contain the absolute project root if getcwd() worked
        $this->assertStringNotContainsString($projectRoot . '/', $result);
    }

    public function testStackTracePreservesNamespaceSeparators(): void
    {
        $output = new BufferedOutput;
        $handler = new BootstrapErrorHandler($output);

        $handler->handle(new Exception('Namespace error'));

        $result = $output->fetch();

        $this->assertStringContainsString(self::class . '->testStackTracePreservesNamespaceSeparators()', $result);
        $this->assertStringNotContainsString(str_replace('\\', '/', self::class), $result);
    }

    public function testHandleNoAnsi(): void
    {
        $output = new BufferedOutput;
        $handler = new BootstrapErrorHandler($output, decorated: false);

        $exception = new Exception('User code error');

        $reflection = new ReflectionClass($exception);
        $fileProperty = $reflection->getProperty('file');
        $fileProperty->setValue($exception, getcwd() . '/app/Providers/AppServiceProvider.php');

        $handler->handle($exception);

        $result = $output->fetch();

        $this->assertStringContainsString('Tip: Try the following', $result);
        $this->assertStringNotContainsString("\u{1F4A1}", $result);
        $this->assertFalse($output->isDecorated());
    }

    public function testHandleAnsi(): void
    {
        $output = new BufferedOutput;
        $output->setDecorated(true);
        $handler = new BootstrapErrorHandler($output, decorated: true);

        $exception = new Exception('User code error');

        $reflection = new ReflectionClass($exception);
        $fileProperty = $reflection->getProperty('file');
        $fileProperty->setValue($exception, getcwd() . '/app/Providers/AppServiceProvider.php');

        $handler->handle($exception);

        $result = $output->fetch();

        $this->assertStringContainsString("\u{1F4A1} Try the following", $result);
        $this->assertStringNotContainsString('Tip: Try the following', $result);
        $this->assertTrue($output->isDecorated());
    }
}
