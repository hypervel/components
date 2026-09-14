<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\View;

use Generator;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Alert;
use Hypervel\Console\View\Components\AskWithCompletion;
use Hypervel\Console\View\Components\BulletList;
use Hypervel\Console\View\Components\Choice;
use Hypervel\Console\View\Components\Confirm;
use Hypervel\Console\View\Components\Error;
use Hypervel\Console\View\Components\Info;
use Hypervel\Console\View\Components\Success;
use Hypervel\Console\View\Components\Task;
use Hypervel\Console\View\Components\TwoColumnDetail;
use Hypervel\Console\View\Components\Warn;
use Hypervel\Console\View\TaskResult;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class ComponentsTest extends TestCase
{
    public function testAlert(): void
    {
        $output = new BufferedOutput;

        (new Alert($output))->render('The application is in the [production] environment');

        $this->assertStringContainsString(
            'THE APPLICATION IS IN THE [PRODUCTION] ENVIRONMENT.',
            $output->fetch()
        );
    }

    public function testBulletList(): void
    {
        $output = new BufferedOutput;

        (new BulletList($output))->render([
            'ls -la',
            'php artisan inspire',
        ]);

        $output = $output->fetch();

        $this->assertStringContainsString('⇂ ls -la', $output);
        $this->assertStringContainsString('⇂ php artisan inspire', $output);
    }

    public function testSuccess(): void
    {
        $output = new BufferedOutput;

        (new Success($output))->render('The application is in the [production] environment');

        $this->assertStringContainsString('SUCCESS  The application is in the [production] environment.', $output->fetch());
    }

    public function testError(): void
    {
        $output = new BufferedOutput;

        (new Error($output))->render('The application is in the [production] environment');

        $this->assertStringContainsString('ERROR  The application is in the [production] environment.', $output->fetch());
    }

    public function testInfo(): void
    {
        $output = new BufferedOutput;

        (new Info($output))->render('The application is in the [production] environment');

        $this->assertStringContainsString('INFO  The application is in the [production] environment.', $output->fetch());
    }

    public function testConfirm(): void
    {
        $output = m::mock(OutputStyle::class);

        $output->expects('confirm')
            ->with('Question?', false)
            ->andReturnTrue();

        $result = (new Confirm($output))->render('Question?');
        $this->assertTrue($result);

        $output->expects('confirm')
            ->with('Question?', true)
            ->andReturnTrue();

        $result = (new Confirm($output))->render('Question?', true);
        $this->assertTrue($result);
    }

    public function testChoice(): void
    {
        $output = m::mock(OutputStyle::class);

        $output->expects('askQuestion')
            ->with(m::type(ChoiceQuestion::class))
            ->andReturn('a');

        $result = (new Choice($output))->render('Question?', ['a', 'b']);
        $this->assertSame('a', $result);
    }

    public function testAskWithCompletionAcceptsGeneratorValues(): void
    {
        $output = m::mock(OutputStyle::class);

        $output->shouldReceive('askQuestion')
            ->with(m::on(function (Question $question): bool {
                $this->assertSame(['a', 'b'], $question->getAutocompleterValues());

                return true;
            }))
            ->once()
            ->andReturn('b');

        $choices = (function (): Generator {
            yield 'a';
            yield 'b';
        })();

        $result = (new AskWithCompletion($output))->render('Question?', $choices);

        $this->assertSame('b', $result);
    }

    public function testTask(): void
    {
        $output = new BufferedOutput;

        (new Task($output))->render('My task', fn (): int => TaskResult::Success->value);
        $result = $output->fetch();
        $this->assertStringContainsString('My task', $result);
        $this->assertStringContainsString('DONE', $result);

        (new Task($output))->render('My task', fn (): int => TaskResult::Failure->value);
        $result = $output->fetch();
        $this->assertStringContainsString('My task', $result);
        $this->assertStringContainsString('FAIL', $result);

        (new Task($output))->render('My task', fn (): int => TaskResult::Skipped->value);
        $result = $output->fetch();
        $this->assertStringContainsString('My task', $result);
        $this->assertStringContainsString('SKIPPED', $result);
    }

    public function testTwoColumnDetail(): void
    {
        $output = new BufferedOutput;

        (new TwoColumnDetail($output))->render('First', 'Second');
        $result = $output->fetch();
        $this->assertStringContainsString('First', $result);
        $this->assertStringContainsString('Second', $result);
    }

    public function testTwoColumnDetailPreservesTrailingPunctuationInValue(): void
    {
        $output = new BufferedOutput;

        (new TwoColumnDetail($output))->render('Key', 'value!');
        $result = $output->fetch();
        $this->assertStringContainsString('value!', $result);
    }

    public function testWarn(): void
    {
        $output = new BufferedOutput;

        (new Warn($output))->render('The application is in the [production] environment');

        $this->assertStringContainsString('WARN  The application is in the [production] environment.', $output->fetch());
    }
}
