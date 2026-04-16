<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Hypervel\Console\OutputStyle;
use Symfony\Component\Console\Question\Question;

class Secret extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $question, bool $fallback = true): mixed
    {
        $question = new Question($question);

        $question->setHidden(true)->setHiddenFallback($fallback);

        /** @var OutputStyle $output */
        $output = $this->output;

        return $this->usingQuestionHelper(fn () => $output->askQuestion($question));
    }
}
