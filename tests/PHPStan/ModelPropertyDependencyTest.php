<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use PHPUnit\Framework\Attributes\Large;

use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function str_replace;

#[Large]
class ModelPropertyDependencyTest extends ResultCacheTestCase
{
    protected string $fixture = 'ModelPropertyDependency';

    /**
     * A config change can move a migration to another connection with no analyzed file changing,
     * so the files using models are analyzed again, and the cached schema is aggregated again.
     */
    public function testChangedConfigTheMigrationsReadReanalyzesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        file_put_contents($applicationPath . '/config/schema.php', "<?php\n\nreturn ['connection' => 'pgsql'];\n");

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));
    }

    /**
     * A migration directory that a configured glob starts matching has no tracked path, so its schema
     * changes invalidate the result cache.
     */
    public function testMigrationDirectoryAGlobStartsMatchingReanalyzesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();
        $parameters = "databaseMigrationsPath:\n    - '{$applicationPath}/database/migrations'\n    - '{$applicationPath}/modules/*/migrations'";

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath, parameters: $parameters), 1));

        mkdir($applicationPath . '/modules/profile/migrations', 0777, true);
        file_put_contents($applicationPath . '/modules/profile/migrations/2026_01_03_000000_add_nickname_to_users_table.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Hypervel\Database\Migrations\Migration;
            use Hypervel\Database\Schema\Blueprint;
            use Hypervel\Support\Facades\Schema;

            return new class extends Migration {
                public function getConnection(): ?string
                {
                    return config('schema.connection');
                }

                public function up(): void
                {
                    Schema::table('users', function (Blueprint $table) {
                        $table->string('nickname');
                    });
                }
            };
            PHP);

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath, parameters: $parameters), 0));
    }

    /**
     * A schema dump directory that a configured glob starts matching has no tracked path either.
     */
    public function testSchemaDumpDirectoryAGlobStartsMatchingReanalyzesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();
        $parameters = "disableMigrationScan: true\nsquashedMigrationsPath:\n    - '{$applicationPath}/modules/*/schema'";

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath, parameters: $parameters), 0));

        mkdir($applicationPath . '/modules/profile/schema', 0777, true);
        file_put_contents(
            $applicationPath . '/modules/profile/schema/sqlite-schema.sql',
            'CREATE TABLE IF NOT EXISTS "users" ("id" integer primary key autoincrement not null, "name" varchar not null);' . "\n",
        );

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath, parameters: $parameters), 1));
    }

    /**
     * PHPStan analyzes the dependents of a changed file again only when its declarations change, but
     * a model's casts also follow the body of its casts() method.
     */
    public function testChangedCastsMethodBodyReanalyzesModelUsage(): void
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
    public function testChangedModelParentReanalyzesModelUsage(): void
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
    public function testChangedConfigTheModelConnectionReadsReanalyzesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        file_put_contents($applicationPath . '/config/models.php', "<?php\n\nreturn ['connection' => 'pgsql'];\n");

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));
    }

    /**
     * A constant a migration reads is declared outside the migrations, so its file is checked like them.
     */
    public function testChangedConstantTheMigrationsReadReanalyzesModelUsage(): void
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
    public function testChangedConstantABuilderAttributeReadsReanalyzesModelUsage(): void
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
    public function testChangedConstantAGuardReadsReanalyzesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();
        $constantsPath = $applicationPath . '/app/Models/FlagColumns.php';

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        file_put_contents($constantsPath, str_replace("NAME = 'name'", "NAME = 'nickname'", file_get_contents($constantsPath)));

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));
    }

    /**
     * A migration's parent can override the inherited method the migration calls, changing the schema
     * while the file of the method that ran does not change. The migration's own getConnection() reads
     * nothing from its parents, so no connection lookup tracks them either.
     */
    public function testChangedMigrationParentReanalyzesModelUsage(): void
    {
        $applicationPath = $this->copyApplication();
        $parentPath = $applicationPath . '/app/Models/UsersMigration.php';

        file_put_contents($applicationPath . '/app/Models/NicknameMigration.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ModelPropertyDependency\Models;

            use Hypervel\Database\Migrations\Migration;
            use Hypervel\Database\Schema\Blueprint;
            use Hypervel\Support\Facades\Schema;

            abstract class NicknameMigration extends Migration
            {
                protected function addNickname(): void
                {
                    Schema::table('users', function (Blueprint $table) {
                        $table->string('nickname');
                    });
                }
            }
            PHP);
        file_put_contents($parentPath, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ModelPropertyDependency\Models;

            abstract class UsersMigration extends NicknameMigration
            {
            }
            PHP);
        file_put_contents($applicationPath . '/database/migrations/2026_01_03_000000_add_nickname_to_users_table.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use ModelPropertyDependency\Models\UsersMigration;

            return new class extends UsersMigration {
                public function getConnection(): ?string
                {
                    return config('schema.connection');
                }

                public function up(): void
                {
                    $this->addNickname();
                }
            };
            PHP);

        $this->assertSame([], $this->diagnostics($this->analyse($applicationPath), 0));

        file_put_contents($parentPath, str_replace("{\n}", "{\n    protected function addNickname(): void\n    {\n    }\n}", file_get_contents($parentPath)));

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));
    }

    /**
     * Calls through a model or a relation depend on the model, not on its builder, and a builder's
     * passthru methods come from a property default, which is not part of its declarations.
     *
     * PHPStan gives a class's tracked inputs to every file depending on it, so each route's calls name
     * their own classes, and one route's inputs cannot cover a gap in another's.
     */
    public function testChangedBuilderReanalyzesForwardedCalls(): void
    {
        $applicationPath = $this->copyApplication();
        $builderPath = $applicationPath . '/app/Models/UserBuilder.php';
        $builder = file_get_contents($builderPath);

        $this->assertSame(['Usage.php:14 property.notFound'], $this->diagnostics($this->analyse($applicationPath), 1));

        // A passthru edit alone, since a declaration change reanalyzes the builder's direct callers anyway.
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
    public function testChangedQueryClassReanalyzesBuilderUsage(): void
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
}
