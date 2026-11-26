<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Key;
use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Tests\TestCase;
use RuntimeException;

use function Hypervel\Prompts\text;

class SynchronizedOutputTest extends TestCase
{
    public function testWrapsEachRedrawInASynchronizedUpdate(): void
    {
        Prompt::fake(['J', 'e', Key::ENTER]);

        text(label: 'What is your name?');

        $content = Prompt::content();
        $redraws = substr_count($content, "\e[J");

        $this->assertGreaterThan(0, $redraws);
        $this->assertSame($redraws, substr_count($content, "\e[?2026h"));
        $this->assertSame($redraws, substr_count($content, "\e[?2026l"));
        $this->assertSame($redraws, preg_match_all('/\e\[\?2026h(?:(?!\e\[\?2026[hl]).)*\e\[J(?:(?!\e\[\?2026[hl]).)*\e\[\?2026l/s', $content));
    }

    public function testEndsTheSynchronizedUpdateWhenARedrawFails(): void
    {
        Prompt::fake(['J']);

        $failure = new RuntimeException('unable to write output');
        $output = new class(decorated: true) extends BufferedConsoleOutput {
            public ?RuntimeException $failure = null;

            private bool $synchronizing = false;

            protected function doWrite(string $message, bool $newline): void
            {
                if ($this->synchronizing && $this->failure !== null) {
                    [$failure, $this->failure] = [$this->failure, null];

                    throw $failure;
                }

                $this->synchronizing = $message === "\e[?2026h";

                parent::doWrite($message, $newline);
            }
        };
        $output->failure = $failure;
        Prompt::setOutput($output);

        try {
            text(label: 'What is your name?');

            $this->fail('Expected the redraw to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, substr_count($output->fetch(), "\e[?2026h\e[?2026l"));
    }

    public function testDoesNotWrapTheFirstRender(): void
    {
        Prompt::fake([Key::ENTER]);

        text(label: 'What is your name?', default: 'Jess');

        $this->assertStringStartsNotWith("\e[?2026h", Prompt::content());
    }

    public function testKeepsTheTrailingNewLinesOfTheRedrawnFrame(): void
    {
        Prompt::fake(['J', Key::ENTER, Key::ENTER]);

        text(label: 'What is your name?');

        $next = new TextPrompt(label: 'And your city?', default: 'Bern');
        $next->prompt();

        $this->assertSame(1, $next->newLinesWritten());
    }
}
