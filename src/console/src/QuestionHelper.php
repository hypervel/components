<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Console\View\Components\TwoColumnDetail;
use Hypervel\Support\Stringable;
use Override;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\SymfonyQuestionHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class QuestionHelper extends SymfonyQuestionHelper
{
    #[Override]
    protected function writePrompt(OutputInterface $output, Question $question): void
    {
        $text = OutputFormatter::escapeTrailingBackslash($question->getQuestion());

        $text = $this->ensureEndsWithPunctuation($text);

        $text = "  <fg=default;options=bold>{$text}</></>";

        $default = $question->getDefault();

        if ($question->isMultiline()) {
            $text .= sprintf(' (press %s to continue)', PHP_OS_FAMILY === 'Windows'
                ? '<comment>Ctrl+Z</comment> then <comment>Enter</comment>'
                : '<comment>Ctrl+D</comment>');
        }

        switch (true) {
            case $default === null:
                $text = sprintf('<info>%s</info>', $text);

                break;
            case $question instanceof ConfirmationQuestion:
                $text = sprintf('<info>%s (yes/no)</info> [<comment>%s</comment>]', $text, $default ? 'yes' : 'no');

                break;
            case $question instanceof ChoiceQuestion:
                $choices = $question->getChoices();
                $text = sprintf('<info>%s</info> [<comment>%s</comment>]', $text, OutputFormatter::escape($choices[$default] ?? $default));

                break;
            default:
                $text = sprintf('<info>%s</info> [<comment>%s</comment>]', $text, OutputFormatter::escape($default));

                break;
        }

        $output->writeln($text);

        if ($question instanceof ChoiceQuestion) {
            foreach ($question->getChoices() as $key => $value) {
                (new TwoColumnDetail($output))->render($value, $key); /* @phpstan-ignore-line */
            }
        }

        $output->write('<options=bold>❯ </>');
    }

    /**
     * Ensure the given string ends with punctuation.
     */
    protected function ensureEndsWithPunctuation(string $string): string
    {
        if (! (new Stringable($string))->endsWith(['?', ':', '!', '.'])) {
            return "{$string}:";
        }

        return $string;
    }
}
