<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Symfony\Component\Console\Question\ChoiceQuestion;

class Choice extends Component
{
    /**
     * Render the component using the given arguments.
     *
     * @param array<array-key, string> $choices
     */
    public function render(string $question, array $choices, mixed $default = null, ?int $attempts = null, bool $multiple = false): mixed
    {
        return $this->usingQuestionHelper(
            fn () => $this->output->askQuestion(
                $this->getChoiceQuestion($question, $choices, $default)
                    ->setMaxAttempts($attempts)
                    ->setMultiselect($multiple)
            ),
        );
    }

    /**
     * Get a ChoiceQuestion instance that handles array keys like Prompts.
     */
    protected function getChoiceQuestion(string $question, array $choices, mixed $default): ChoiceQuestion
    {
        return new class($question, $choices, $default) extends ChoiceQuestion {
            protected function isAssoc(array $array): bool
            {
                return ! array_is_list($array);
            }
        };
    }
}
