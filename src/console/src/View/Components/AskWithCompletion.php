<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Symfony\Component\Console\Question\Question;

class AskWithCompletion extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $question, array|callable $choices, ?string $default = null): mixed
    {
        $question = new Question($question, $default);

        is_callable($choices)
            ? $question->setAutocompleterCallback($choices)
            : $question->setAutocompleterValues($choices);

        return $this->usingQuestionHelper(
            fn () => $this->output->askQuestion($question)
        );
    }
}
