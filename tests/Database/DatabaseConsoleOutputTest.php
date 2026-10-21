<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Database\Console\ShowCommand;
use Hypervel\Database\Console\TableCommand;
use Hypervel\Tests\TestCase;
use JsonException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class DatabaseConsoleOutputTest extends TestCase
{
    public function testTableCommandRendersValidJson(): void
    {
        [$command, $output] = $this->tableCommand();
        $data = ['comment' => '<info>Pending</info> a\>b'];

        $command->renderJson($data);

        $this->assertSame(json_encode($data, JSON_THROW_ON_ERROR) . "\n", $output->fetch());
    }

    public function testTableCommandRejectsUnencodableJson(): void
    {
        [$command] = $this->tableCommand();

        $this->expectException(JsonException::class);

        $command->renderJson(['value' => NAN]);
    }

    public function testTableCommandPreservesCommentsAndDefaultsInText(): void
    {
        [$command, $output] = $this->tableCommand();

        $command->renderText([
            'table' => [
                'schema_qualified_name' => 'orders', 'comment' => '<info>Pending</info> a\>b',
                'columns' => 1, 'size' => null, 'engine' => null, 'collation' => null,
            ],
            'columns' => collect([
                ['column' => 'status', 'attributes' => collect(), 'default' => "'<info>draft</info> a\\>b'", 'type' => 'varchar'],
            ]),
            'indexes' => collect(),
            'foreign_keys' => collect(),
        ]);
        $text = $output->fetch();

        $this->assertStringContainsString('<info>Pending</info> a\>b', $text);
        $this->assertStringContainsString("'<info>draft</info> a\\>b' varchar", $text);
    }

    public function testTableCommandIncludesPartialIndexAttribute(): void
    {
        [$command] = $this->tableCommand();

        $this->assertSame(['btree', 'compound', 'partial'], $command->indexAttributes([
            'name' => 'records_active_index',
            'columns' => ['account_id', 'archived_at'],
            'type' => 'btree',
            'unique' => false,
            'primary' => false,
            'partial' => true,
        ]));
    }

    public function testShowCommandRendersValidJson(): void
    {
        [$command, $output] = $this->showCommand();
        $data = ['comment' => '<info>Pending</info> a\>b'];

        $command->renderJson($data);

        $this->assertSame(json_encode($data, JSON_THROW_ON_ERROR) . "\n", $output->fetch());
    }

    public function testShowCommandRejectsUnencodableJson(): void
    {
        [$command] = $this->showCommand();

        $this->expectException(JsonException::class);

        $command->renderJson(['value' => NAN]);
    }

    public function testShowCommandPreservesTableCommentsInText(): void
    {
        [$command, $output] = $this->showCommand(OutputInterface::VERBOSITY_VERBOSE);

        $command->renderText([
            'platform' => ['name' => 'SQLite', 'version' => '3.45', 'connection' => 'sqlite', 'config' => [], 'open_connections' => null],
            'tables' => collect([
                ['schema' => null, 'table' => 'orders', 'size' => null, 'engine' => null, 'comment' => '<info>Pending</info> a\>b', 'rows' => null],
            ]),
        ]);

        $this->assertStringContainsString('<info>Pending</info> a\>b', $output->fetch());
    }

    /**
     * Create a table command probe that writes to a buffer.
     *
     * @return array{TableCommandProbe, BufferedOutput}
     */
    private function tableCommand(): array
    {
        $output = new BufferedOutput;
        $command = new TableCommandProbe;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        return [$command, $output];
    }

    /**
     * Create a show command probe that writes to a buffer.
     *
     * @return array{ShowCommandProbe, BufferedOutput}
     */
    private function showCommand(int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $output = new BufferedOutput($verbosity);
        $command = new ShowCommandProbe;
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        return [$command, $output];
    }
}

class TableCommandProbe extends TableCommand
{
    /**
     * Render the given table information as JSON.
     */
    public function renderJson(array $data): void
    {
        $this->displayJson($data);
    }

    /**
     * Render the given table information as console text.
     */
    public function renderText(array $data): void
    {
        $this->components = new Factory($this->output);

        $this->displayForCli($data);
    }

    /**
     * Get the display attributes for the given index.
     */
    public function indexAttributes(array $index): array
    {
        return $this->getAttributesForIndex($index)->values()->all();
    }
}

class ShowCommandProbe extends ShowCommand
{
    /**
     * Render the given database information as JSON.
     */
    public function renderJson(array $data): void
    {
        $this->displayJson($data);
    }

    /**
     * Render the given database information as console text.
     */
    public function renderText(array $data): void
    {
        $this->components = new Factory($this->output);

        $this->displayForCli($data);
    }
}
