<?php

declare(strict_types=1);

namespace Hypervel\Console\View\Components;

use Hypervel\Console\OutputStyle;
use InvalidArgumentException;

/**
 * @method void alert(string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method mixed ask(string $question, string $default = null, bool $multiline = false)
 * @method mixed askWithCompletion(string $question, array|callable $choices, string $default = null)
 * @method void bulletList(array $elements, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method mixed choice(string $question, array $choices, $default = null, int $attempts = null, bool $multiple = false)
 * @method bool confirm(string $question, bool $default = false)
 * @method void info(string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method void success(string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method void error(string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method void line(string $style, string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method mixed secret(string $question, bool $fallback = true)
 * @method void task(string $description, ?callable $task = null, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method void twoColumnDetail(string $first, ?string $second = null, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method void warn(string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 */
class Factory
{
    /**
     * Create a new factory instance.
     */
    public function __construct(
        protected OutputStyle $output,
    ) {
    }

    /**
     * Dynamically handle calls into the component instance.
     *
     * @throws InvalidArgumentException
     */
    public function __call(string $method, array $parameters): mixed
    {
        $component = '\Hypervel\Console\View\Components\\' . ucfirst($method);

        throw_unless(class_exists($component), new InvalidArgumentException(sprintf(
            'Console component [%s] not found.',
            $method
        )));

        return (new $component($this->output))->render(...$parameters);
    }
}
