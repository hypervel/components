<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\OutputStyle;
use Hypervel\Database\Console\ShowCommand;
use Hypervel\Database\Console\TableCommand;
use Hypervel\Tests\TestCase;
use JsonException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class DatabaseConsoleJsonTest extends TestCase
{
    public function testTableCommandRendersValidJson(): void
    {
        [$command, $output] = $this->tableCommand();

        $command->renderJson(['value' => 'text']);

        $this->assertSame("{\"value\":\"text\"}\n", $output->fetch());
    }

    public function testTableCommandRejectsUnencodableJson(): void
    {
        [$command] = $this->tableCommand();

        $this->expectException(JsonException::class);

        $command->renderJson(['value' => NAN]);
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

        $command->renderJson(['value' => 'text']);

        $this->assertSame("{\"value\":\"text\"}\n", $output->fetch());
    }

    public function testShowCommandRejectsUnencodableJson(): void
    {
        [$command] = $this->showCommand();

        $this->expectException(JsonException::class);

        $command->renderJson(['value' => NAN]);
    }

    /**
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
     * @return array{ShowCommandProbe, BufferedOutput}
     */
    private function showCommand(): array
    {
        $output = new BufferedOutput;
        $command = new ShowCommandProbe;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        return [$command, $output];
    }
}

class TableCommandProbe extends TableCommand
{
    public function renderJson(array $data): void
    {
        $this->displayJson($data);
    }

    public function indexAttributes(array $index): array
    {
        return $this->getAttributesForIndex($index)->values()->all();
    }
}

class ShowCommandProbe extends ShowCommand
{
    public function renderJson(array $data): void
    {
        $this->displayJson($data);
    }
}
