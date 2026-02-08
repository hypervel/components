<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Contracts\Config\Repository as RepositoryContract;
use Hypervel\Config\Repository;
use Hypervel\Foundation\Console\CliDumper;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\VarDumper\Caster\ReflectionCaster;
use Symfony\Component\VarDumper\Cloner\VarCloner;

/**
 * @internal
 * @coversNothing
 */
class CliDumperTest extends TestCase
{
    use HasMockedApplication;

    protected $config;

    protected $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->getConfig();

        $this->container = $this->getApplication([
            RepositoryContract::class => fn () => $this->config,
        ]);

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

    public function testString()
    {
        $output = $this->dump('string');

        $expected = "\"string\" // app/routes/console.php:18\n";

        $this->assertSame($expected, $output);
    }

    public function testInteger()
    {
        $output = $this->dump(1);

        $expected = "1 // app/routes/console.php:18\n";

        $this->assertSame($expected, $output);
    }

    public function testFloat()
    {
        $output = $this->dump(1.1);

        $expected = "1.1 // app/routes/console.php:18\n";

        $this->assertSame($expected, $output);
    }

    public function testArray()
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

    public function testBoolean()
    {
        $output = $this->dump(true);

        $expected = "true // app/routes/console.php:18\n";

        $this->assertSame($expected, $output);
    }

    public function testObject()
    {
        $user = new stdClass();
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

    public function testNull()
    {
        $output = $this->dump(null);

        $expected = "null // app/routes/console.php:18\n";

        $this->assertSame($expected, $output);
    }

    public function testWhenIsFileViewIsNotViewCompiled()
    {
        $file = '/my-work-directory/routes/console.php';

        $output = new BufferedOutput();
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

    public function testWhenIsFileViewIsViewCompiled()
    {
        $file = '/my-work-directory/storage/framework/views/6687c33c38b71a8560.php';

        $output = new BufferedOutput();
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

    public function testGetOriginalViewCompiledFile()
    {
        $compiled = __DIR__ . '/../fixtures/fake-compiled-view.php';
        $original = '/my-work-directory/resources/views/welcome.blade.php';

        $output = new BufferedOutput();
        $dumper = new CliDumper(
            $output,
            '/my-work-directory',
            '/my-work-directory/storage/framework/views'
        );

        $reflection = new ReflectionClass($dumper);
        $method = $reflection->getMethod('getOriginalFileForCompiledView');

        $this->assertSame($original, $method->invoke($dumper, $compiled));
    }

    public function testWhenGetOriginalViewCompiledFileFails()
    {
        $compiled = __DIR__ . '/../fixtures/fake-compiled-view-without-source-map.php';
        $original = $compiled;

        $output = new BufferedOutput();
        $dumper = new CliDumper(
            $output,
            '/my-work-directory',
            '/my-work-directory/storage/framework/views'
        );

        $reflection = new ReflectionClass($dumper);
        $method = $reflection->getMethod('getOriginalFileForCompiledView');

        $this->assertSame($original, $method->invoke($dumper, $compiled));
    }

    public function testUnresolvableSource()
    {
        CliDumper::resolveDumpSourceUsing(fn () => null);

        $output = $this->dump('string');

        $expected = "\"string\"\n";

        $this->assertSame($expected, $output);
    }

    protected function dump($value)
    {
        $compiledViewPath = $this->config->get('view.config.view_path');

        $output = new BufferedOutput();
        $dumper = new CliDumper($output, '/my-work-directory', $compiledViewPath);

        $cloner = tap(new VarCloner())->addCasters(ReflectionCaster::UNSET_CLOSURE_FILE_INFO);

        $dumper->dumpWithSource($cloner->cloneVar($value));

        return $output->fetch();
    }

    protected function tearDown(): void
    {
        CliDumper::resolveDumpSourceUsing(null);
    }
}
