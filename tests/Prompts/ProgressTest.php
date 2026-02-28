<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Prompt;
use Hypervel\Support\Collection;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\progress;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class ProgressTest extends TestCase
{
    /**
     * @dataProvider progressBarProvider
     * @param mixed $steps
     */
    public function testRendersProgressBar($steps): void
    {
        Prompt::fake();

        progress(
            label: 'Adding States',
            steps: $steps,
            callback: fn () => null,
        );

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     ┌ Adding States ───────────────────────────────────────────────┐
     │                                                              │
     └───────────────────────────────────────────────────────── 0/4 ┘
    OUTPUT);

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     │ ███████████████                                              │
     └───────────────────────────────────────────────────────── 1/4 ┘
    OUTPUT);

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     │ ██████████████████████████████                               │
     └───────────────────────────────────────────────────────── 2/4 ┘
    OUTPUT);

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     │ █████████████████████████████████████████████                │
     └───────────────────────────────────────────────────────── 3/4 ┘
    OUTPUT);

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     ┌ Adding States ───────────────────────────────────────────────┐
     │ ████████████████████████████████████████████████████████████ │
     └───────────────────────────────────────────────────────── 4/4 ┘
    OUTPUT);
    }

    public static function progressBarProvider(): array
    {
        return [
            'array' => [['Alabama', 'Alaska', 'Arizona', 'Arkansas']],
            'integer' => [4],
            'collection' => [Collection::make(['Alabama', 'Alaska', 'Arizona', 'Arkansas'])],
        ];
    }

    public function testRendersProgressBarWithoutLabel(): void
    {
        Prompt::fake();

        progress(
            label: '',
            steps: 6,
            callback: function ($item, $progress) {
                $progress->hint((string) $item);
            }
        );

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
     ┌──────────────────────────────────────────────────────────────┐
     │                                                              │
     └───────────────────────────────────────────────────────── 0/6 ┘
    OUTPUT);
    }

    public function testReturnsCallbackResults(): void
    {
        Prompt::fake();

        $result = progress(
            label: 'Uppercasing States',
            steps: ['Alabama', 'Alaska', 'Arizona', 'Arkansas'],
            callback: function ($item) {
                return strtoupper($item);
            }
        );

        $this->assertSame(['ALABAMA', 'ALASKA', 'ARIZONA', 'ARKANSAS'], $result);
    }

    public function testCanUpdateLabelAndHintWhileRendering(): void
    {
        Prompt::fake();

        $states = [
            'Alabama',
            'Alaska',
            'Arizona',
            'Arkansas',
            'California',
            'Colorado',
        ];

        progress(
            label: 'Adding States',
            steps: $states,
            callback: function ($item, $progress) {
                $progress->label(strtoupper($item));
                $progress->hint(strtolower($item));
            }
        );

        Prompt::assertOutputContains('Adding States');

        foreach ($states as $state) {
            Prompt::assertOutputContains(strtoupper($state));
            Prompt::assertOutputContains(strtolower($state));
        }
    }

    public function testReturnsManualProgressBarWhenNoCallback(): void
    {
        Prompt::fake();

        $states = [
            'Alabama',
            'Alaska',
            'Arizona',
            'Arkansas',
            'California',
            'Colorado',
        ];

        $progress = progress(
            label: 'Adding States',
            steps: count($states),
        );

        $progress->start();

        foreach ($states as $state) {
            $progress->advance();
        }

        $progress->finish();

        Prompt::assertOutputContains('Adding States');
        Prompt::assertOutputDoesntContain('Alabama');
    }

    public function testCanUpdateLabelAndHintInManualMode(): void
    {
        Prompt::fake();

        $states = [
            'Alabama',
            'Alaska',
            'Arizona',
            'Arkansas',
            'California',
            'Colorado',
        ];

        $progress = progress(
            label: 'Adding States',
            steps: count($states),
        );

        $progress->start();

        foreach ($states as $state) {
            $progress
                ->label(strtoupper($state))
                ->hint(strtolower($state))
                ->advance();
        }

        $progress->finish();

        Prompt::assertOutputContains('Adding States');

        foreach ($states as $state) {
            Prompt::assertOutputContains(strtoupper($state));
            Prompt::assertOutputContains(strtolower($state));
        }
    }
}
