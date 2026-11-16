<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\OutputStyle;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigratorTest extends TestCase
{
    private BufferedOutput $output;

    private array $expectedLines = [];

    public Migrator $subject;

    /**
     * Configure an isolated SQLite database for the migration fixtures.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.default', 'sqlite');
        $app->make('config')->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Set up migration state in the test coroutine.
     */
    protected function setUpInCoroutine(): void
    {
        $this->output = new BufferedOutput;
        $this->subject = $this->app->make('migrator');
        $this->subject->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        $this->subject->getRepository()->createRepository();
    }

    public function testMigrate(): void
    {
        $this->expectInfo('Running migrations.');

        $this->expectTask('2014_10_12_000000_create_people_table', 'DONE');
        $this->expectTask('2015_10_04_000000_modify_people_table', 'DONE');
        $this->expectTask('2016_10_04_000000_modify_people_table', 'DONE');
        $this->expectTask('2017_10_04_000000_add_age_to_people', 'SKIPPED');

        $this->subject->run([__DIR__ . '/Fixtures/Migrator']);
        $this->assertExpectedOutput();

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('people'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('people', 'first_name'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('people', 'last_name'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('people', 'age'));
    }

    public function testMigrateWithoutOutput(): void
    {
        $this->app->forgetInstance('migrator');
        $this->subject = $this->app->make('migrator');

        $this->subject->run([__DIR__ . '/Fixtures/Migrator']);
        $this->assertExpectedOutput();

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('people'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('people', 'first_name'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('people', 'last_name'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('people', 'age'));
    }

    public function testWithSkippedMigrations(): void
    {
        $this->app->forgetInstance('migrator');
        $this->subject = $this->app->make('migrator');

        Migrator::withoutMigrations(['2015_10_04_000000_modify_people_table.php', '2016_10_04_000000_modify_people_table']);

        $this->subject->run([__DIR__ . '/Fixtures/Migrator']);
        $this->assertExpectedOutput();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('people'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('people', 'first_name'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('people', 'last_name'));

        Migrator::withoutMigrations([]);
        $this->subject->run([__DIR__ . '/Fixtures/Migrator']);
        $this->assertExpectedOutput();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('people', 'first_name'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('people', 'last_name'));
    }

    public function testShouldSkip(): void
    {
        $this->assertFalse($this->subject->shouldSkip(__DIR__ . '/Fixtures/Migrator/2014_10_12_000000_create_people_table.php'));
        $this->assertTrue($this->subject->shouldSkip(__DIR__ . '/Fixtures/Migrator/2017_10_04_000000_add_age_to_people.php'));
    }

    public function testRollback(): void
    {
        DB::statement('CREATE TABLE people(id INT, first_name VARCHAR, last_name VARCHAR);');
        $this->subject->getRepository()->log('2014_10_12_000000_create_people_table', 1);
        $this->subject->getRepository()->log('2015_10_04_000000_modify_people_table', 1);
        $this->subject->getRepository()->log('2016_10_04_000000_modify_people_table', 1);

        $this->expectInfo('Rolling back migrations.');

        $this->expectTask('2016_10_04_000000_modify_people_table', 'DONE');
        $this->expectTask('2015_10_04_000000_modify_people_table', 'DONE');
        $this->expectTask('2014_10_12_000000_create_people_table', 'DONE');

        $this->subject->rollback([__DIR__ . '/Fixtures/Migrator']);
        $this->assertExpectedOutput();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('people'));
    }

    public function testPretendMigrate(): void
    {
        $this->expectInfo('Running migrations.');

        $this->expectTwoColumnDetail('CreatePeopleTable');
        $this->expectBulletList([
            'create table "people" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "password" varchar not null, "remember_token" varchar, "created_at" datetime, "updated_at" datetime)',
            'create unique index "people_email_unique" on "people" ("email")',
        ]);

        $this->expectTwoColumnDetail('ModifyPeopleTable');
        $this->expectBulletList(['alter table "people" add column "first_name" varchar']);

        $this->expectTwoColumnDetail('2016_10_04_000000_modify_people_table');
        $this->expectBulletList(['alter table "people" add column "last_name" varchar']);

        $this->subject->run([__DIR__ . '/Fixtures/Migrator'], ['pretend' => true]);
        $this->assertExpectedOutput();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('people'));
    }

    public function testIgnorePretendModeForCallbackData(): void
    {
        // Create two tables with different columns so that we can query it later
        // with the new method DB::withoutPretending().

        Schema::create('table_1', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('column_1');
        });

        Schema::create('table_2', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('column_2')->default('default_value');
        });

        // From here on we simulate to be in pretend mode. This normally is done by
        // running the migration with the option --pretend.

        DB::pretend(function (): void {
            // Returns an empty array because we are in pretend mode.
            $tablesEmpty = DB::select("SELECT name FROM sqlite_master WHERE type='table'");

            $this->assertSame([], $tablesEmpty);

            // Returns an array with two tables because we ignore pretend mode.
            $tablesList = DB::withoutPretending(function (): array {
                return DB::select("SELECT name FROM sqlite_master WHERE type='table'");
            });

            $this->assertNotSame([], $tablesList);

            // The following would not be possible in pretend mode, if the
            // method DB::withoutPretending() did not exist,
            // because nothing is executed in pretend mode.
            foreach ($tablesList as $table) {
                if (in_array($table->name, ['sqlite_sequence', 'migrations'], true)) {
                    continue;
                }

                $columnsEmpty = DB::select("PRAGMA table_info({$table->name})");

                $this->assertSame([], $columnsEmpty);

                $columnsList = DB::withoutPretending(function () use ($table): array {
                    return DB::select("PRAGMA table_info({$table->name})");
                });

                $this->assertNotSame([], $columnsList);
                $this->assertCount(2, $columnsList);

                // Confirm that we are still in pretend mode. This column should
                // not be added. We query the table columns again to ensure the
                // count is still two.
                DB::statement("ALTER TABLE {$table->name} ADD COLUMN column_3 varchar(255) DEFAULT 'default_value' NOT NULL");

                $columnsList = DB::withoutPretending(function () use ($table): array {
                    return DB::select("PRAGMA table_info({$table->name})");
                });

                $this->assertCount(2, $columnsList);
            }
        });

        Schema::dropIfExists('table_1');
        Schema::dropIfExists('table_2');
    }

    public function testIgnorePretendModeForCallbackOutputDynamicContentIsShown(): void
    {
        // Persist data to table we can work with.
        $this->expectInfo('Running migrations.');
        $this->expectTask('2014_10_12_000000_create_people_is_dynamic_table', 'DONE');

        $this->subject->run([__DIR__ . '/Fixtures/Migrator/pretending/2014_10_12_000000_create_people_is_dynamic_table.php'], ['pretend' => false]);
        $this->assertExpectedOutput();

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('people'));

        // Test the actual functionality.
        $this->expectInfo('Running migrations.');
        $this->expectTwoColumnDetail('DynamicContentIsShown');
        $this->expectBulletList([
            'create table "blogs" ("id" integer primary key autoincrement not null, "url" varchar, "name" varchar)',
            'insert into "blogs" ("url") values (\'www.janedoe.com\'), (\'www.johndoe.com\')',
            'ALTER TABLE \'pseudo_table_name\' MODIFY \'column_name\' VARCHAR(191)',
            'select * from "people"',
            'insert into "blogs" ("id", "name") values (1, \'Jane Doe Blog\')',
            'insert into "blogs" ("id", "name") values (2, \'John Doe Blog\')',
        ]);

        $this->subject->run([__DIR__ . '/Fixtures/Migrator/pretending/2023_10_17_000000_dynamic_content_is_shown.php'], ['pretend' => true]);
        $this->assertExpectedOutput();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('blogs'));

        Schema::dropIfExists('people');
    }

    public function testIgnorePretendModeForCallbackOutputDynamicContentNotShown(): void
    {
        // Persist data to table we can work with.
        $this->expectInfo('Running migrations.');
        $this->expectTask('2014_10_12_000000_create_people_non_dynamic_table', 'DONE');

        $this->subject->run([__DIR__ . '/Fixtures/Migrator/pretending/2014_10_12_000000_create_people_non_dynamic_table.php'], ['pretend' => false]);
        $this->assertExpectedOutput();

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('people'));

        // Test the actual functionality.
        $this->expectInfo('Running migrations.');
        $this->expectTwoColumnDetail('DynamicContentNotShown');
        $this->expectBulletList([
            'create table "blogs" ("id" integer primary key autoincrement not null, "url" varchar, "name" varchar)',
            'insert into "blogs" ("url") values (\'www.janedoe.com\'), (\'www.johndoe.com\')',
            'ALTER TABLE \'pseudo_table_name\' MODIFY \'column_name\' VARCHAR(191)',
            'select * from "people"',
        ]);

        $this->subject->run([__DIR__ . '/Fixtures/Migrator/pretending/2023_10_17_000000_dynamic_content_not_shown.php'], ['pretend' => true]);
        $this->assertExpectedOutput();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('blogs'));

        Schema::dropIfExists('people');
    }

    /**
     * Expect an informational output message.
     */
    protected function expectInfo(string $message): void
    {
        $this->expectedLines[] = $message;
    }

    /**
     * Expect a two-column detail in the output.
     */
    protected function expectTwoColumnDetail(string $first, ?string $second = null): void
    {
        $this->expectedLines[] = $first;

        if ($second) {
            $this->expectedLines[] = $second;
        }
    }

    /**
     * Expect a bullet list in the output.
     *
     * @param list<string> $elements
     */
    protected function expectBulletList(array $elements): void
    {
        foreach ($elements as $element) {
            $this->expectedLines[] = "⇂ {$element}";
        }
    }

    /**
     * Expect a migration task and its result in the output.
     */
    protected function expectTask(string $description, string $result): void
    {
        $this->expectedLines[] = $description;
        $this->expectedLines[] = $result;
    }

    /**
     * Assert the buffered output contains every expected line.
     */
    protected function assertExpectedOutput(): void
    {
        $output = $this->output->fetch();

        foreach ($this->expectedLines as $expected) {
            $this->assertStringContainsString($expected, $output);
        }

        $this->expectedLines = [];
    }
}
