<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\form;
use function Hypervel\Prompts\outro;
use function Hypervel\Prompts\text;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class FormTest extends TestCase
{
    public function testCanRunMultipleSteps()
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->confirm('Are you sure?')
            ->submit();

        $this->assertEquals([
            'Luke',
            'PHP',
            true,
        ], $responses);
    }

    public function testCanRevertSteps()
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::ENTER,
            Key::CTRL_U,
            Key::CTRL_U,
            ...array_fill(0, 4, Key::BACKSPACE),
            'J',
            'e',
            's',
            's',
            Key::ENTER,
            Key::DOWN,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->confirm('Are you sure?')
            ->submit();

        $this->assertEquals([
            'Jess',
            'JS',
            true,
        ], $responses);
    }

    public function passesAllAvailableResponsesToEachStep()
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->add(fn ($responses) => confirm("Are you sure your name is {$responses[0]} and your language is {$responses[1]}?"))
            ->submit();

        $this->assertStringContainsString('Are you sure your name is Luke and your language is PHP?', Prompt::output());
    }

    public function canKeyAResponseByAGivenString()
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?', name: 'name')
            ->select('What is your language?', ['PHP', 'JS'], name: 'language')
            ->add(fn ($responses) => confirm("Are you sure your name is {$responses['name']} and your language is {$responses['language']}?"))
            ->submit();

        $this->assertStringContainsString('Are you sure your name is Luke and your language is PHP?', Prompt::output());
    }

    public function doesNotAllowRevertingNormalPrompts()
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::ENTER,
            Key::CTRL_U,
            Key::ENTER,
        ]);

        form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->submit();

        $confirm = confirm('Are you sure?');

        $this->assertStringContainsString('This cannot be reverted.', Prompt::output());
        $this->assertTrue($confirm);
    }

    public function testDoesNotAllowRevertingTheFirstStep()
    {
        Prompt::fake([Key::CTRL_U, Key::ENTER]);

        $responses = form()->confirm('Are you sure?')->submit();

        $this->assertEquals([true], $responses);
    }

    public function testSkipStepsOverStepsThatHaveNoUserInputWhenReverting()
    {
        Prompt::fake([
            '3',
            Key::ENTER,
            Key::CTRL_U,
            '0',
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('How old are you?')
            ->info('This should be skipped')
            ->alert('This should be skipped')
            ->confirm('Are you sure?')
            ->submit();

        $this->assertEquals(['30', null, null, true], $responses);
    }

    public function testWillNotSkipOverTheFirstStepWhenReverting()
    {
        Prompt::fake([
            Key::CTRL_U,
            Key::ENTER,
        ]);

        $responses = form()
            ->info('This should not be skipped')
            ->confirm('Are you sure?')
            ->submit();

        $this->assertEquals([null, true], $responses);
    }

    public function testPrefillsExistingResponsesWhenReverting()
    {
        Prompt::fake([
            'J',
            'e',
            's',
            's',
            Key::ENTER,
            Key::CTRL_U,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->confirm('Are you sure?')
            ->submit();

        $this->assertEquals('Jess', $responses[0]);
    }

    public function testStopStepsAtTheMomentOfReverting()
    {
        Prompt::fake([
            '2',
            '7',
            Key::ENTER,
            Key::DOWN,
            Key::CTRL_U,
            Key::ENTER,
            Key::ENTER,
        ]);

        form()
            ->text('What is your age?')
            ->add(function () {
                $confirmed = confirm('Are you sure?');

                if (! $confirmed) {
                    outro('This should not appear!');
                }
            })->submit();

        Prompt::assertOutputDoesntContain('This should not appear!');
    }

    public function testCanRevertStepsWithConditions()
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::DOWN,
            Key::ENTER,
            Key::CTRL_U,
            Key::UP,
            Key::ENTER,
            '8',
            '.',
            '3',
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->addIf(fn ($responses) => $responses[1] === 'PHP', fn ($responses) => text('Which version?'))
            ->confirm('Are you sure?')
            ->submit();

        $this->assertEquals([
            'Luke',
            'PHP',
            '8.3',
            true,
        ], $responses);
    }

    public function testLeavesSkippedConditionalFieldEmpty()
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::DOWN,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->addIf(fn ($responses) => $responses[1] === 'PHP', fn ($responses) => text('Which version?'))
            ->confirm('Are you sure?')
            ->submit();

        $this->assertEquals([
            'Luke',
            'JS',
            null,
            true,
        ], $responses);
    }
}
