<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Config\Repository;
use Hypervel\Context\CoroutineContext;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Console\CliDumper;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\VarDumper\Caster\ReflectionCaster;
use Symfony\Component\VarDumper\Cloner\VarCloner;

use function Hypervel\Coroutine\parallel;

class CliDumperTest extends TestCase
{
    protected Repository $config;

    protected Application $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->getConfig();

        $this->container = new Application;
        $this->container->singleton('config', fn () => $this->config);

        CliDumper::resolveDumpSourceUsing(function () {
            return [
                '/my-work-director/app/routes/console.php',
                'app/routes/console.php',
                18,
            ];
        });
    }

    protected function getConfig(array $config = []): Repository
    {
        return new Repository(array_merge([
            'app' => ['url' => 'http://localhost'],
            'view' => ['config' => ['view_path' => 'view_path']],
        ], $config));
    }

    public function testString(): void
    {
        $output = $this->dump('string');

        $expected = "\"string\" // app/routes/console.php:18\n";

        $this->assertSame($expected, $output);
    }

    public function testInteger(): void
    {
        $output = $this->dump(1);

        $expected = "1 // app/routes/console.php:18\n";

        $this->assertSame($expected, $output);
    }

    public function testFloat(): void
    {
        $output = $this->dump(1.1);

        $expected = "1.1 // app/routes/console.php:18\n";

        $this->assertSame($expected, $output);
    }

    public function testArray(): void
    {
        $output = $this->dump(['string', 1, 1.1, ['string', 1, 1.1]]);

        $expected = <<<'EOF'
        array:4 [
          0 => "string"
          1 => 1
          2 => 1.1
          3 => array:3 [
            0 => "string"
            1 => 1
            2 => 1.1
          ]
        ] // app/routes/console.php:18

        EOF;

        $this->assertSame(
            str_replace("\r\n", "\n", $expected),
            str_replace("\r\n", "\n", $output)
        );
    }

    public function testBoolean(): void
    {
        $output = $this->dump(true);

        $expected = "true // app/routes/console.php:18\n";

        $this->assertSame($expected, $output);
    }

    public function testObject(): void
    {
        $user = new stdClass;
        $user->name = 'Guus';

        $output = $this->dump($user);

        $objectId = spl_object_id($user);

        $expected = <<<EOF
        {#{$objectId}
          +"name": "Guus"
        } // app/routes/console.php:18

        EOF;

        $this->assertSame(
            str_replace("\r\n", "\n", $expected),
            str_replace("\r\n", "\n", $output)
        );
    }

    public function testNull(): void
    {
        $output = $this->dump(null);

        $expected = "null // app/routes/console.php:18\n";

        $this->assertSame($expected, $output);
    }

    public function testWhenIsFileViewIsNotViewCompiled(): void
    {
        $file = '/my-work-directory/routes/console.php';

        $output = new BufferedOutput;
        $dumper = new CliDumper(
            $output,
            '/my-work-directory',
            '/my-work-directory/storage/framework/views'
        );

        $reflection = new ReflectionClass($dumper);
        $method = $reflection->getMethod('isCompiledViewFile');
        $isCompiledViewFile = $method->invoke($dumper, $file);

        $this->assertFalse($isCompiledViewFile);
    }

    public function testWhenIsFileViewIsViewCompiled(): void
    {
        $file = '/my-work-directory/storage/framework/views/6687c33c38b71a8560.php';

        $output = new BufferedOutput;
        $dumper = new CliDumper(
            $output,
            '/my-work-directory',
            '/my-work-directory/storage/framework/views'
        );

        $reflection = new ReflectionClass($dumper);
        $method = $reflection->getMethod('isCompiledViewFile');
        $isCompiledViewFile = $method->invoke($dumper, $file);

        $this->assertTrue($isCompiledViewFile);
    }

    public function testGetOriginalViewCompiledFile(): void
    {
        $compiled = __DIR__ . '/../Fixtures/fake-compiled-view.php';
        $original = '/my-work-directory/resources/views/welcome.blade.php';

        $output = new BufferedOutput;
        $dumper = new CliDumper(
            $output,
            '/my-work-directory',
            '/my-work-directory/storage/framework/views'
        );

        $reflection = new ReflectionClass($dumper);
        $method = $reflection->getMethod('getOriginalFileForCompiledView');

        $this->assertSame($original, $method->invoke($dumper, $compiled));
    }

    public function testWhenGetOriginalViewCompiledFileFails(): void
    {
        $compiled = __DIR__ . '/../Fixtures/fake-compiled-view-without-source-map.php';
        $original = $compiled;

        $output = new BufferedOutput;
        $dumper = new CliDumper(
            $output,
            '/my-work-directory',
            '/my-work-directory/storage/framework/views'
        );

        $reflection = new ReflectionClass($dumper);
        $method = $reflection->getMethod('getOriginalFileForCompiledView');

        $this->assertSame($original, $method->invoke($dumper, $compiled));
    }

    public function testUnresolvableSource(): void
    {
        CliDumper::resolveDumpSourceUsing(fn () => null);

        $output = $this->dump('string');

        $expected = "\"string\"\n";

        $this->assertSame($expected, $output);
    }

    public function testUnresolvableLine(): void
    {
        CliDumper::resolveDumpSourceUsing(function () {
            return [
                '/my-work-directory/resources/views/welcome.blade.php',
                'resources/views/welcome.blade.php',
                null,
            ];
        });

        $output = $this->dump('hey from view');

        $expected = "\"hey from view\" // resources/views/welcome.blade.php\n";

        $this->assertSame($expected, $output);
    }

    public function testFailedCompiledViewReadsReturnTheCompiledPath(): void
    {
        $compiled = '/my-work-directory/storage/framework/views/missing.php';
        $dumper = new CliDumper(
            new BufferedOutput,
            '/my-work-directory',
            '/my-work-directory/storage/framework/views',
        );

        $method = (new ReflectionClass($dumper))->getMethod('getOriginalFileForCompiledView');

        $this->assertSame($compiled, $method->invoke($dumper, $compiled));
    }

    public function testDumpingGuardIsClearedWhenSourceResolutionThrows(): void
    {
        $exception = new RuntimeException('source resolution failed');
        CliDumperFixture::resolveDumpSourceUsing(static function () use ($exception): never {
            throw $exception;
        });

        $dumper = new CliDumperFixture(
            new BufferedOutput,
            '/my-work-directory',
            '/my-work-directory/storage/framework/views',
        );

        try {
            $dumper->dumpWithSource((new VarCloner)->cloneVar('value'));
            $this->fail('Expected source resolution to fail.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertFalse(CoroutineContext::has(CliDumperFixture::dumpingContextKey()));
    }

    public function testConcurrentDumpsEachIncludeTheirSource(): void
    {
        CliDumperFixture::resolveDumpSourceUsing(static function (): array {
            usleep(10_000);

            return [
                '/my-work-directory/app/routes/console.php',
                'app/routes/console.php',
                18,
            ];
        });

        $output = new BufferedOutput;
        $dumper = new CliDumperFixture(
            $output,
            '/my-work-directory',
            '/my-work-directory/storage/framework/views',
        );
        $cloner = new VarCloner;

        parallel([
            fn () => $dumper->dumpWithSource($cloner->cloneVar('first')),
            fn () => $dumper->dumpWithSource($cloner->cloneVar('second')),
        ]);

        $this->assertSame(2, substr_count($output->fetch(), '// app/routes/console.php:18'));
    }

    protected function dump(mixed $value): string
    {
        $compiledViewPath = $this->config->get('view.config.view_path');

        $output = new BufferedOutput;
        $dumper = new CliDumper($output, '/my-work-directory', $compiledViewPath);

        $cloner = tap(new VarCloner)->addCasters(ReflectionCaster::UNSET_CLOSURE_FILE_INFO);

        $dumper->dumpWithSource($cloner->cloneVar($value));

        return $output->fetch();
    }
}

class CliDumperFixture extends CliDumper
{
    public static function dumpingContextKey(): string
    {
        return self::DUMPING_CONTEXT_KEY;
    }
}
