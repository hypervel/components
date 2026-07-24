<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Application;
use Hypervel\Tests\TestCase;
use ReflectionMethod;
use Symfony\Component\Process\PhpProcess;

class ConsoleApplicationCompatibilityTest extends TestCase
{
    public function testContractMethodsAreDeclaredLocally(): void
    {
        $all = new ReflectionMethod(Application::class, 'all');
        $run = new ReflectionMethod(Application::class, 'run');

        $this->assertSame(Application::class, $all->getDeclaringClass()->getName());
        $this->assertSame('array', (string) $all->getReturnType());
        $this->assertSame(Application::class, $run->getDeclaringClass()->getName());
        $this->assertSame('int', (string) $run->getReturnType());
    }

    public function testApplicationLoadsWhenComposerPreloadsAnOlderSymfonyConsoleVersion(): void
    {
        $process = new PhpProcess(<<<'PHP'
<?php

require 'vendor/autoload.php';

if (! class_alias(
    Hypervel\Tests\Console\Fixtures\LegacySymfonyApplication::class,
    Symfony\Component\Console\Application::class,
)) {
    exit(1);
}

exit(class_exists(Hypervel\Console\Application::class) ? 0 : 1);
PHP, dirname(__DIR__, 2));

        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput() ?: $process->getOutput(),
        );
    }
}
