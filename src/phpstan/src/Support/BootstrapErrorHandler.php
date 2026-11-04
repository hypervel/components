<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use NunoMaduro\Collision\Provider;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Whoops\Inspector\Inspector;
use Whoops\Inspector\InspectorFactory;

use function array_map;
use function array_merge;
use function class_exists;
use function function_exists;
use function getcwd;
use function htmlspecialchars;
use function implode;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function substr;
use function Termwind\render;
use function Termwind\renderUsing;
use function trim;

use const ENT_QUOTES;
use const PHP_EOL;

final class BootstrapErrorHandler
{
    private const string TITLE = 'Application bootstrap failed';

    /**
     * Create a bootstrap error handler.
     */
    public function __construct(
        private ?OutputInterface $output = null,
        private ?bool $decorated = null,
    ) {
    }

    /**
     * Render the bootstrap failure.
     */
    public function handle(Throwable $throwable): void
    {
        $output = $this->output ?? new ConsoleOutput;
        $errorOutput = $output instanceof ConsoleOutput ? $output->getErrorOutput() : $output;

        if ($this->decorated !== null) {
            $output->setDecorated($this->decorated);
            $errorOutput->setDecorated($this->decorated);
        }

        $decorated = $errorOutput->isDecorated();

        if ($decorated && $this->renderWithCollision($throwable, $errorOutput)) {
            return;
        }

        $this->renderWithSymfonyStyle($throwable, $errorOutput);
    }

    /**
     * Render the failure with Collision when it is installed.
     */
    private function renderWithCollision(Throwable $throwable, OutputInterface $output): bool
    {
        if (! class_exists(Provider::class) || ! function_exists('Termwind\render')) {
            return false;
        }

        try {
            $message = $this->formatError($throwable, includeErrorDetails: false);
            $title = self::TITLE;

            $body = $this->buildCollisionBody($message);

            renderUsing($output);

            render(<<<HTML
                <div class="mx-2 mt-1">
                    <div class="px-1 bg-red-500 text-white font-bold">
                        {$title}
                    </div>
                    <div class="px-1">
                        {$body}
                    </div>
                    <div class="mt-1 text-gray">Exception Details</div>
                    <hr class="border-t text-gray"/>
                </div>

            HTML);

            $provider = new Provider;
            $handler = $provider->register()
                ->getHandler()
                ->setOutput($output);

            $inspector = match (true) {
                class_exists(Inspector::class) => new Inspector($throwable),
                class_exists(InspectorFactory::class) => (new InspectorFactory)->create($throwable),
                default => null,
            };

            if ($inspector !== null) {
                $handler->setInspector($inspector);
            }

            $handler->handle($throwable);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Render the failure with Symfony console styling.
     */
    private function renderWithSymfonyStyle(Throwable $throwable, OutputInterface $output): void
    {
        $message = $this->formatError($throwable, decorated: $output->isDecorated());

        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $io->block(self::TITLE, null, 'fg=white;bg=red', ' ', true);

        foreach ($this->splitLines($message) as $line) {
            // Exception messages and traces are literal text, not console markup.
            $line = OutputFormatter::escape($line);

            match (true) {
                $line === '' => $io->newLine(),
                str_starts_with($line, 'Error:') => $io->writeln('<error>' . $line . '</error>'),
                str_starts_with($line, 'Stack trace:') => $io->writeln('<options=bold>' . $line . '</options=bold>'),
                preg_match('/^#\d+/', $line) === 1 => $io->writeln('<fg=gray>' . $line . '</fg=gray>'),
                default => $io->writeln($line),
            };
        }
    }

    /**
     * Format a bootstrap failure.
     *
     * Upstream blames the framework for failures under a /vendor/ path, which misreports custom
     * vendor directories and path repositories, so every failure gets the same guidance.
     */
    private function formatError(Throwable $throwable, bool $includeErrorDetails = true, bool $decorated = true): string
    {
        $parts = [
            'PHPStan was unable to bootstrap your application.',
            '',
            'Hypervel PHPStan boots your Hypervel application during analysis to provide smarter results.',
            'The application reported an error while booting, so the analysis could not continue.',
            '',
            sprintf('%s Try the following:', $this->getTipSymbol($decorated)),
            ' - Check your environment variables in the .env file',
            ' - Check the Hypervel logs in storage/logs/hypervel.log for more details',
            ' - Run composer dump-autoload to ensure classes are indexed',
            ' - Verify service provider registration and configuration',
            ' - Verify the application boots by running php artisan about, or vendor/bin/testbench about for a package',
        ];

        return $this->appendErrorDetails($parts, $throwable, $includeErrorDetails);
    }

    /**
     * Append the error message and stack trace when requested.
     *
     * @param list<string> $parts
     */
    private function appendErrorDetails(array $parts, Throwable $throwable, bool $includeErrorDetails): string
    {
        if ($includeErrorDetails) {
            $parts = array_merge($parts, [
                '',
                sprintf('Error: %s', $this->formatErrorMessage($throwable)),
                '',
                'Stack trace:',
                $this->formatStackTrace($throwable),
            ]);
        }

        return implode(PHP_EOL, $parts);
    }

    /**
     * Format the exception message.
     */
    private function formatErrorMessage(Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());

        return $message !== '' ? $message : sprintf('Unhandled %s with no message', $throwable::class);
    }

    /**
     * Format the stack trace relative to the project root.
     */
    private function formatStackTrace(Throwable $throwable): string
    {
        $projectRoot = getcwd();
        $trace = $throwable->getTraceAsString();

        // Upstream converts every backslash to normalize Windows paths, which also
        // rewrites namespace separators. Swoole has no native Windows support, so
        // paths already use forward slashes.
        return $projectRoot !== false
            ? str_replace($projectRoot . '/', '', $trace)
            : $trace;
    }

    /**
     * Split a message into lines.
     *
     * @return array<int, string>
     */
    private function splitLines(string $message): array
    {
        return preg_split('/\r\n|\r|\n/', $message) ?: [];
    }

    /**
     * Build the Termwind markup for a message.
     */
    private function buildCollisionBody(string $message): string
    {
        return implode('', array_map(fn (string $line): string => match (true) {
            $line === '' => '<div class="mb-1"></div>',
            str_starts_with($line, ' - ') => '<div class="pl-3">• ' . $this->escapeForHtml(substr($line, 3)) . '</div>',
            default => '<div>' . $this->escapeForHtml($line) . '</div>',
        }, $this->splitLines($message)));
    }

    /**
     * Escape a value for Termwind markup.
     */
    private function escapeForHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Return the tip prefix for the output mode.
     */
    private function getTipSymbol(bool $decorated): string
    {
        return $decorated ? "\u{1F4A1}" : 'Tip:';
    }
}
