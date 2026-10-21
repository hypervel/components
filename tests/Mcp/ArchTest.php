<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp;

use Exception;
use Hypervel\Console\Command;
use Hypervel\Mcp\Server\Contracts\Annotation;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\Testing\TestListResponse;
use Hypervel\Mcp\Server\Testing\TestResponse;
use Hypervel\Tests\TestCase as UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Finder\Finder;

class ArchTest extends UnitTestCase
{
    public function testSourceUsesStrictTypesAndNoDebuggingHelpers(): void
    {
        $source = dirname(__DIR__, 2) . '/src/mcp/src';

        foreach ((new Finder)->files()->name('*.php')->in($source) as $file) {
            $class = 'Hypervel\Mcp\\' . str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));

            if (in_array($class, [TestResponse::class, TestListResponse::class], true)) {
                continue;
            }

            $contents = $file->getContents();
            $this->assertStringContainsString('declare(strict_types=1);', $contents, $class);
            $this->assertDoesNotMatchRegularExpression('/\b(dd|dump|var_dump|die)\s*\(/', $contents, $class);
        }
    }

    public function testMcpMethodsImplementTheMethodContract(): void
    {
        foreach ($this->classesIn('Server/Methods') as $class) {
            $this->assertTrue($class->implementsInterface(Method::class), $class->getName());
        }
    }

    public function testToolAnnotationsOnlyImplementTheAnnotationContract(): void
    {
        foreach ($this->classesIn('Server/Tools/Annotations') as $class) {
            $this->assertSame([Annotation::class], $class->getInterfaceNames(), $class->getName());
        }
    }

    public function testResourceAnnotationsOnlyImplementTheAnnotationContract(): void
    {
        foreach ($this->classesIn('Server/Annotations') as $class) {
            $this->assertSame([Annotation::class], $class->getInterfaceNames(), $class->getName());
        }
    }

    public function testContractsAreInterfaces(): void
    {
        foreach ($this->classesIn('Server/Contracts') as $class) {
            $this->assertTrue($class->isInterface(), $class->getName());
        }
    }

    public function testExceptionsExtendException(): void
    {
        foreach ([...$this->classesIn('Exceptions'), ...$this->classesIn('Client/Exceptions')] as $class) {
            $this->assertTrue($class->isSubclassOf(Exception::class), $class->getName());
        }
    }

    public function testCommandsExtendCommandAndHaveTheCommandAttribute(): void
    {
        foreach ($this->classesIn('Console/Commands') as $class) {
            $this->assertTrue($class->isSubclassOf(Command::class), $class->getName());
            $this->assertNotEmpty($class->getAttributes(AsCommand::class), $class->getName());
        }
    }

    /**
     * Reflect direct members of a package namespace, excluding nested concerns.
     *
     * @return list<ReflectionClass>
     */
    private function classesIn(string $directory): array
    {
        $classes = [];
        $path = dirname(__DIR__, 2) . '/src/mcp/src/' . $directory;

        foreach ((new Finder)->files()->name('*.php')->depth(0)->in($path) as $file) {
            $classes[] = new ReflectionClass('Hypervel\Mcp\\'
                . str_replace('/', '\\', $directory) . '\\' . $file->getBasename('.php'));
        }

        return $classes;
    }
}
