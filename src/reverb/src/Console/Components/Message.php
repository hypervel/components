<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Console\Components;

use Hypervel\Console\View\Components\Component;
use Symfony\Component\Console\Output\OutputInterface;

class Message extends Component
{
    /**
     * Render the component using the given arguments.
     */
    public function render(string $message, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $this->renderView('message', [
            'message' => $message,
        ], $verbosity);
    }

    /**
     * Compile the given view contents.
     */
    protected function compile(string $view, array $data): string
    {
        extract($data);

        ob_start();

        include __DIR__ . "/views/{$view}.php";

        return tap(ob_get_contents(), function () {
            ob_end_clean();
        });
    }
}
