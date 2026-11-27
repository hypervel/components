<?php

declare(strict_types=1);

namespace Hypervel\Testing\Console;

use Hypervel\Console\OutputStyle;
use Override;
use PHPUnit\Framework\Assert as PHPUnit;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * @internal
 */
class FakeOutputStyle extends OutputStyle
{
    /**
     * The expected questions that have not been asked yet, as [question, answer] pairs.
     *
     * @var array<int, array{0: string, 1: mixed}>
     */
    protected array $questions;

    /**
     * The questions that were asked, keyed by their expected question text.
     *
     * @var array<string, Question>
     */
    protected array $asked = [];

    /**
     * The failure from a question that should not have been asked.
     */
    protected ?string $failure = null;

    /**
     * Create a new fake output style instance.
     *
     * @param array<int, array{0: string, 1: mixed}> $questions
     * @param array<string, array{expected: array, strict: bool}> $choices
     */
    public function __construct(
        InputInterface $input,
        private FakeBufferedOutput $output,
        array $questions = [],
        protected array $choices = [],
    ) {
        parent::__construct($input, $output);

        $this->questions = array_values($questions);
    }

    /**
     * Match the prompt's styled frame only when the command will print it.
     */
    public function capturePromptFrame(string $frame): void
    {
        if ($this->shouldWrite(0)) {
            $this->output->matchPromptFrame($frame);
        }
    }

    /**
     * Get the command's output buffer.
     */
    #[Override]
    public function getOutput(): FakeBufferedOutput
    {
        return $this->output;
    }

    /**
     * Answer the next expected question.
     */
    #[Override]
    public function askQuestion(Question $question): mixed
    {
        $text = $question->getQuestion();

        foreach ($this->questions as $i => [$expected, $answer]) {
            if ($text !== $expected) {
                continue;
            }

            if ($i !== array_key_first($this->questions)) {
                PHPUnit::fail($this->failure ??= 'Question "' . $text . '" was asked before "' . reset($this->questions)[0] . '".');
            }

            unset($this->questions[$i]);

            $this->asked[$expected] = $question;

            return $answer;
        }

        PHPUnit::fail($this->failure ??= 'Unexpected question "' . $text . '" was asked.');
    }

    /**
     * Get the message for the first unmet question expectation.
     */
    public function failure(): ?string
    {
        if ($this->failure) {
            return $this->failure;
        }

        if ($this->questions !== []) {
            return 'Question "' . reset($this->questions)[0] . '" was not asked.';
        }

        return null;
    }

    /**
     * Get the expected choices with the choices that were actually offered.
     *
     * @return array<string, array{expected: array, strict: bool, actual: null|iterable}>
     */
    public function choices(): array
    {
        foreach ($this->choices as $text => $choice) {
            $question = $this->asked[$text] ?? null;

            $this->choices[$text]['actual'] = $question instanceof ChoiceQuestion && ! array_is_list($choice['expected'])
                ? $question->getChoices()
                : $question?->getAutocompleterValues();
        }

        return $this->choices;
    }
}
