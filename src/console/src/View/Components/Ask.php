<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Hypervel\Console\OutputStyle;
use Symfony\Component\Console\Question\Question;

class Ask extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $question, ?string $default = null, bool $multiline = false): mixed
    {
        /** @var OutputStyle $output */
        $output = $this->output;

        return $this->usingQuestionHelper(
            fn () => $output->askQuestion(
                (new Question($question, $default))
                    ->setMultiline($multiline)
            )
        );
    }
}
