<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Exception;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Routing\Controller;
use Hypervel\Tests\TestCase as UnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

class ArchTest extends UnitTestCase
{
    public function testPasskeysSourceUsesStrictTypes(): void
    {
        foreach ($this->phpFiles($this->sourcePath()) as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            $this->assertStringContainsString(
                'declare(strict_types=1);',
                $contents,
                $file->getPathname() . ' must declare strict types.'
            );
        }
    }

    public function testPasskeysSourceDoesNotUseDebuggingHelpers(): void
    {
        foreach ($this->phpFiles($this->sourcePath()) as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            $this->assertDoesNotMatchRegularExpression(
                '/\b(dd|dump|var_dump|die|ray)\s*\(/',
                $contents,
                $file->getPathname() . ' must not use debugging helpers.'
            );
        }
    }

    public function testControllersExtendControllerAndUseControllerSuffix(): void
    {
        foreach ($this->classesIn('Http/Controllers') as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue($reflection->isSubclassOf(Controller::class), $class . ' must extend Controller.');
            $this->assertStringEndsWith('Controller', $reflection->getShortName());
        }
    }

    public function testActionsAreInvokable(): void
    {
        foreach ($this->classesIn('Actions') as $class) {
            $this->assertTrue(method_exists($class, '__invoke'), $class . ' must be invokable.');
        }
    }

    public function testExceptionsExtendExceptionAndUseExceptionSuffix(): void
    {
        foreach ($this->classesIn('Exceptions') as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue($reflection->isSubclassOf(Exception::class), $class . ' must extend Exception.');
            $this->assertStringEndsWith('Exception', $reflection->getShortName());
        }
    }

    public function testRequestsExtendFormRequestAndUseRequestSuffix(): void
    {
        foreach ($this->classesIn('Http/Requests') as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue($reflection->isSubclassOf(FormRequest::class), $class . ' must extend FormRequest.');
            $this->assertStringEndsWith('Request', $reflection->getShortName());
        }
    }

    public function testPasskeysSourceDoesNotCallEnvDirectly(): void
    {
        foreach ($this->phpFiles($this->sourcePath()) as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            $this->assertDoesNotMatchRegularExpression(
                '/\benv\s*\(/',
                $contents,
                $file->getPathname() . ' must not call env() directly.'
            );
        }
    }

    public function testPasskeysSourceDoesNotReferenceTestsNamespace(): void
    {
        foreach ($this->phpFiles($this->sourcePath()) as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            $this->assertStringNotContainsString('Hypervel\Tests', $contents, $file->getPathname());
        }
    }

    /**
     * Get PHP files below the given path.
     *
     * @return list<SplFileInfo>
     */
    private function phpFiles(string $path): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Get class names in the given source subdirectory.
     *
     * @return list<class-string>
     */
    private function classesIn(string $directory): array
    {
        $classes = [];
        $basePath = $this->sourcePath() . '/' . $directory;

        foreach ($this->phpFiles($basePath) as $file) {
            $relative = substr($file->getPathname(), strlen($this->sourcePath()) + 1, -4);
            $class = 'Hypervel\Passkeys\\' . str_replace('/', '\\', $relative);

            if (class_exists($class)) {
                /** @var class-string $class */
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Get the Passkeys source path.
     */
    private function sourcePath(): string
    {
        return dirname(__DIR__, 2) . '/src/passkeys/src';
    }
}
