<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Symfony\Component\Process\Process;

use function basename;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function mkdir;
use function sort;
use function str_replace;

class ModelPropertyDependencyTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('PHPStanModelPropertyDependencyTest');
        (new Filesystem)->deleteDirectory($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * A config change can move a migration to another connection with no analysed file changing,
     * so the files using models are analysed again, and the cached schema is aggregated again.
     */
    public function testChangedConfigTheMigrationsReadReanalysesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        file_put_contents($applicationPath . '/config/schema.php', "<?php\n\nreturn ['connection' => 'pgsql'];\n");

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));
    }

    /**
     * PHPStan analyses the dependents of a changed file again only when its declarations change, but
     * a model's casts also follow the body of its casts() method.
     */
    public function testChangedCastsMethodBodyReanalysesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();
        $modelPath = $applicationPath . '/app/Models/User.php';

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        file_put_contents($modelPath, str_replace("'name' => 'string'", "'name' => 'array'", file_get_contents($modelPath)));

        $this->assertSame(
            ['Usage.php:14 property.notFound', 'Usage.php:22 return.type'],
            $this->diagnostics($this->analyse($applicationPath), 1),
        );
    }

    /**
     * A model's connection can come from a property default in a parent, which is not part of the
     * declarations PHPStan compares.
     */
    public function testChangedModelParentReanalysesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();
        $parentPath = $applicationPath . '/app/Models/BaseModel.php';

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        file_put_contents($parentPath, str_replace('$connection = null', "\$connection = 'pgsql'", file_get_contents($parentPath)));

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));
    }

    /**
     * An overriding getConnectionName() can read any config value.
     */
    public function testChangedConfigTheModelConnectionReadsReanalysesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        file_put_contents($applicationPath . '/config/models.php', "<?php\n\nreturn ['connection' => 'pgsql'];\n");

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));
    }

    /**
     * A constant a migration reads is declared outside the migrations, so its file is checked like them.
     */
    public function testChangedConstantTheMigrationsReadReanalysesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();
        $constantsPath = $applicationPath . '/app/Models/Columns.php';

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        file_put_contents($constantsPath, str_replace("NAME = 'name'", "NAME = 'nickname'", file_get_contents($constantsPath)));

        // The cast still makes name an attribute.
        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));
    }

    /**
     * A builder attribute can name its builder by another class's constant, which the model's file does not declare.
     */
    public function testChangedConstantABuilderAttributeReadsReanalysesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();
        $constantsPath = $applicationPath . '/app/Models/BuilderNames.php';

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        // The base builder is not a subclass of itself, so the attribute is ignored and the model creates it.
        file_put_contents($constantsPath, str_replace('UserBuilder::class', '\Hypervel\Database\Eloquent\Builder::class', file_get_contents($constantsPath)));

        $this->assertSame(
            ['MemberUsage.php:17 method.notFound', 'Usage.php:14 property.notFound'],
            $this->diagnostics($this->analyse($applicationPath), 1),
        );
    }

    /**
     * A guard reads one table's facts to decide what happens to another, so the files those facts were
     * read from are checked for every table.
     */
    public function testChangedConstantAGuardReadsReanalysesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();
        $constantsPath = $applicationPath . '/app/Models/FlagColumns.php';

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        file_put_contents($constantsPath, str_replace("NAME = 'name'", "NAME = 'nickname'", file_get_contents($constantsPath)));

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));
    }

    /**
     * Calls through a model or a relation depend on the model, not on its builder, and a builder's
     * passthru methods come from a property default, which is not part of its declarations.
     *
     * PHPStan gives a class's tracked inputs to every file depending on it, so each route's calls name
     * their own classes, and one route's inputs cannot cover a gap in another's.
     */
    public function testChangedBuilderReanalysesForwardedCalls(): void
    {
        $applicationPath = $this->copyApplication();
        $builderPath = $applicationPath . '/app/Models/UserBuilder.php';
        $builder = file_get_contents($builderPath);

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        // A passthru edit alone, since a declaration change reanalyses the builder's direct callers anyway.
        file_put_contents($builderPath, str_replace("['exists']", '[]', $builder));

        $this->assertSame(
            [
                'BuilderUsage.php:17 return.type',
                'ModelUsage.php:17 return.type',
                'RelationUsage.php:17 return.type',
                'Usage.php:14 property.notFound',
            ],
            $this->diagnostics($this->analyse($applicationPath), 1),
        );

        file_put_contents($builderPath, str_replace('function published()', 'function unpublished()', $builder));

        $this->assertSame(
            ['MemberUsage.php:17 method.notFound', 'RelationUsage.php:25 method.notFound', 'Usage.php:14 property.notFound'],
            $this->diagnostics($this->analyse($applicationPath), 1),
        );

        file_put_contents($builderPath, $builder);

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));
    }

    /**
     * Only the builder's getQuery() signature names its query class.
     */
    public function testChangedQueryClassReanalysesBuilderUsage(): void
    {
        $applicationPath = $this->copyApplication();
        $queryPath = $applicationPath . '/app/Models/UserQuery.php';
        $query = file_get_contents($queryPath);

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        // Not a where method, which the base query would handle as a dynamic where.
        file_put_contents($queryPath, str_replace('function onlyActive()', 'function onlyInactive()', $query));

        $this->assertSame(
            ['BuilderUsage.php:25 method.notFound', 'Usage.php:14 property.notFound'],
            $this->diagnostics($this->analyse($applicationPath), 1),
        );

        file_put_contents($queryPath, $query);

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));
    }

    /**
     * Copy the fixture application, with its models, to the temporary directory.
     */
    protected function copyApplication(): string
    {
        $applicationPath = $this->tempDir . '/application';
        (new Filesystem)->copyDirectory(__DIR__ . '/Fixtures/ModelPropertyDependency/application', $applicationPath);
        mkdir($applicationPath . '/bootstrap/cache');

        return $applicationPath;
    }

    /**
     * Run PHPStan from the application over its models, with the result and migration caches on.
     *
     * PHPStan uses its result cache only when it analyses a directory.
     */
    protected function analyse(string $applicationPath): Process
    {
        $repositoryPath = dirname(__DIR__, 2);

        file_put_contents($applicationPath . '/phpstan.neon', <<<NEON
            includes:
                - {$repositoryPath}/src/phpstan/extension.neon
            parameters:
                level: 5
                tmpDir: {$applicationPath}/cache
                enableMigrationCache: true
                paths:
                    - {$applicationPath}/app/Models
                bootstrapFiles:
                    - {$applicationPath}/bootstrap/autoload.php
            NEON);

        $process = new Process(
            [PHP_BINARY, $repositoryPath . '/vendor/bin/phpstan', 'analyse', '--configuration=phpstan.neon', '--no-progress', '--error-format=json', '--no-ansi'],
            $applicationPath,
            timeout: 300,
        );
        $process->run();

        return $process;
    }

    /**
     * Get the file diagnostics as "file:line identifier" entries.
     *
     * @return list<string>
     */
    protected function diagnostics(Process $process, int $exitCode): array
    {
        $this->assertSame($exitCode, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([], $result['errors']);

        $diagnostics = [];

        foreach ($result['files'] as $file => $errors) {
            foreach ($errors['messages'] as $message) {
                $diagnostics[] = basename($file) . ":{$message['line']} {$message['identifier']}";
            }
        }

        sort($diagnostics);

        return $diagnostics;
    }
}
