<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Hypervel\Console\OutputStyle;
use Hypervel\Console\QuestionHelper;
use ReflectionClass;
use Symfony\Component\Console\Helper\SymfonyQuestionHelper;

use function Termwind\render;
use function Termwind\renderUsing;

abstract class Component
{
    /**
     * The list of mutators to apply on the view data.
     *
     * @var array<int, callable(string): string>
     */
    protected array $mutators;

    /**
     * Create a new component instance.
     */
    public function __construct(
        protected OutputStyle $output,
    ) {
    }

    /**
     * Render the given view.
     */
    protected function renderView(string $view, array $data, int $verbosity): void
    {
        renderUsing($this->output);

        render((string) $this->compile($view, $data), $verbosity);
    }

    /**
     * Compile the given view contents.
     */
    protected function compile(string $view, array $data): string
    {
        extract($data);

        ob_start();

        include __DIR__ . '/../../../resources/views/components/' . $view . '.php';

        return tap(ob_get_contents(), function () {
            ob_end_clean();
        });
    }

    /**
     * Mutate the given data with the given set of mutators.
     *
     * @param array<int, string>|string $data
     * @param array<int, class-string> $mutators
     * @return array<int, string>|string
     */
    protected function mutate(array|string $data, array $mutators): array|string
    {
        foreach ($mutators as $mutator) {
            $mutator = new $mutator();

            if (is_iterable($data)) {
                foreach ($data as $key => $value) {
                    $data[$key] = $mutator($value);
                }
            } else {
                $data = $mutator($data);
            }
        }

        return $data;
    }

    /**
     * Perform a question using the component's question helper.
     */
    protected function usingQuestionHelper(callable $callable): mixed
    {
        $property = (new ReflectionClass(OutputStyle::class))
            ->getParentClass()
            ->getProperty('questionHelper');

        $currentHelper = $property->isInitialized($this->output)
            ? $property->getValue($this->output)
            : new SymfonyQuestionHelper();

        $property->setValue($this->output, new QuestionHelper());

        try {
            return $callable();
        } finally {
            $property->setValue($this->output, $currentHelper);
        }
    }
}
