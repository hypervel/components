<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Context\CoroutineContext;
use Hypervel\Foundation\Concerns\ResolvesDumpSource;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\VarDumper\Caster\ReflectionCaster;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper as BaseCliDumper;
use Symfony\Component\VarDumper\VarDumper;

class CliDumper extends BaseCliDumper
{
    use ResolvesDumpSource;

    protected const DUMPING_CONTEXT_KEY = '__foundation.cli_dumper.dumping';

    /**
     * Create a new CLI dumper instance.
     *
     * @param OutputInterface $output
     */
    public function __construct(
        protected mixed $output,
        protected string $basePath,
        protected ?string $compiledViewPath,
    ) {
        parent::__construct();

        $this->setColors($this->supportsColors());
    }

    /**
     * Create a new CLI dumper instance and register it as the default dumper.
     *
     * Boot-only. Registers a process-wide VarDumper handler for the worker
     * lifetime.
     *
     * @param string $basePath
     * @param string $compiledViewPath
     */
    public static function register($basePath, $compiledViewPath): void
    {
        $cloner = tap(new VarCloner)->addCasters(ReflectionCaster::UNSET_CLOSURE_FILE_INFO); // @phpstan-ignore method.notFound (tap proxy __call)

        $dumper = new static(new ConsoleOutput, $basePath, $compiledViewPath);

        VarDumper::setHandler(fn ($value) => $dumper->dumpWithSource($cloner->cloneVar($value)));
    }

    /**
     * Dump a variable with its source file / line.
     */
    public function dumpWithSource(Data $data): void
    {
        if (CoroutineContext::has(self::DUMPING_CONTEXT_KEY)) {
            $this->dump($data);

            return;
        }

        CoroutineContext::set(self::DUMPING_CONTEXT_KEY, true);

        try {
            $output = (string) $this->dump($data, true);
            $lines = explode("\n", $output);

            $lines[array_key_last($lines) - 1] .= $this->getDumpSourceContent();

            $this->output->write(implode("\n", $lines));
        } finally {
            CoroutineContext::forget(self::DUMPING_CONTEXT_KEY);
        }
    }

    /**
     * Get the dump's source console content.
     */
    protected function getDumpSourceContent(): string
    {
        if (is_null($dumpSource = $this->resolveDumpSource())) {
            return '';
        }

        [$file, $relativeFile, $line] = $dumpSource;

        $href = $this->resolveSourceHref($file, $line);

        return sprintf(
            ' <fg=gray>// <fg=gray%s>%s%s</></>',
            is_null($href) ? '' : ";href={$href}",
            $relativeFile,
            is_null($line) ? '' : ":{$line}"
        );
    }

    protected function supportsColors(): bool
    {
        return $this->output->isDecorated();
    }
}
