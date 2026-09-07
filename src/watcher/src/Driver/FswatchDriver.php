<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use InvalidArgumentException;
use RuntimeException;

class FswatchDriver extends AbstractDriver
{
    /** @var array<string, resource> */
    protected array $processes = [];

    /** @var array<string, resource> */
    protected array $pipes = [];

    public function __construct(Option $option)
    {
        parent::__construct($option);

        $result = $this->exec('command -v fswatch');
        if ($result['code'] !== 0) {
            throw new InvalidArgumentException('The FswatchDriver requires the `fswatch` executable.');
        }
    }

    /**
     * Watch for file changes using `fswatch`.
     */
    public function watch(Channel $channel): void
    {
        if ($this->isStopping()) {
            return;
        }

        $watchPaths = $this->option->getWatchPaths();
        $watchTargets = $this->resolveWatchTargets($watchPaths);

        try {
            $this->openProcesses($watchTargets['groups']);

            if ($this->pipes === []) {
                throw new RuntimeException('The fswatch process did not provide an output pipe.');
            }

            $buffers = array_fill_keys(array_keys($this->pipes), '');

            while (true) {
                if ($this->shouldStopWatching($channel)) {
                    return;
                }

                $readyPipes = $this->pipes;
                $writePipes = null;
                $exceptPipes = null;
                $ready = stream_select($readyPipes, $writePipes, $exceptPipes, null);

                if ($this->shouldStopWatching($channel)) {
                    return;
                }

                if ($ready === false) {
                    throw new RuntimeException('Unable to read output from the fswatch process.');
                }

                foreach ($readyPipes as $group => $pipe) {
                    if ($this->shouldStopWatching($channel)) {
                        return;
                    }

                    $result = fread($pipe, 8192);

                    if ($result === false) {
                        throw new RuntimeException('Unable to read output from the fswatch process.');
                    }

                    if ($result === '') {
                        if (feof($pipe)) {
                            throw new RuntimeException('The fswatch process exited unexpectedly.');
                        }

                        throw new RuntimeException('Unable to read output from the fswatch process.');
                    }

                    $this->processOutput(
                        $buffers[$group],
                        $result,
                        $channel,
                        $watchPaths,
                        $watchTargets['entries'],
                    );
                }
            }
        } finally {
            $this->stop();
            $this->closeProcesses();
        }
    }

    /**
     * Process complete NUL-delimited paths while retaining a partial tail.
     *
     * @param list<WatchPath> $watchPaths
     * @param list<array{prefix: string, base: string}> $watchTargets
     */
    protected function processOutput(
        string &$buffer,
        string $chunk,
        Channel $channel,
        array $watchPaths,
        array $watchTargets,
    ): void {
        $buffer .= $chunk;
        $offset = 0;

        while (($separator = strpos($buffer, "\0", $offset)) !== false) {
            $file = substr($buffer, $offset, $separator - $offset);
            $offset = $separator + 1;

            if ($file === '') {
                continue;
            }

            $matched = false;
            foreach ($watchTargets as $watchTarget) {
                if (! str_starts_with($file, $watchTarget['prefix'])) {
                    continue;
                }

                $remainder = substr($file, strlen($watchTarget['prefix']));
                $relativePath = $watchTarget['base'] === '.'
                    ? $remainder
                    : $watchTarget['base'] . '/' . $remainder;

                // A recursive operand can observe a path whose configured base did not exist at startup.
                foreach ($watchPaths as $watchPath) {
                    if ($watchPath->matches($relativePath)) {
                        $matched = true;
                        break 2;
                    }
                }
            }

            if ($matched) {
                $channel->push($file);
            }
        }

        if ($offset > 0) {
            $buffer = substr($buffer, $offset);
        }
    }

    /**
     * Stop the active fswatch processes.
     */
    public function stop(): void
    {
        parent::stop();

        foreach ($this->processes as $process) {
            if (is_resource($process) && proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }
        }
    }

    /**
     * Open every configured fswatch process group.
     *
     * @param array<string, array{recursive: bool, operands: list<string>}> $groups
     */
    protected function openProcesses(array $groups): void
    {
        foreach ($groups as $group => $settings) {
            $this->openProcess($group, $settings['operands'], $settings['recursive']);
        }
    }

    /**
     * Open one fswatch subprocess and retain its output pipe.
     *
     * @param list<string> $operands
     */
    protected function openProcess(string $group, array $operands, bool $recursive): void
    {
        // The argument-list form bypasses a shell whose descendants could keep stdout open after termination.
        $process = proc_open($this->getCommand($operands, $recursive), [STDIN, ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('fswatch failed.');
        }

        $pipe = $pipes[1] ?? null;

        if (! is_resource($pipe)) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }

            proc_close($process);

            throw new RuntimeException('The fswatch process did not provide an output pipe.');
        }

        $this->processes[$group] = $process;
        $this->pipes[$group] = $pipe;
    }

    /**
     * Close every process resource owned by the active watch lifecycle.
     */
    protected function closeProcesses(): void
    {
        foreach (array_keys($this->pipes) as $group) {
            $pipe = $this->pipes[$group];
            unset($this->pipes[$group]);

            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        foreach (array_keys($this->processes) as $group) {
            $process = $this->processes[$group];
            unset($this->processes[$group]);

            if (! is_resource($process)) {
                continue;
            }

            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }

            proc_close($process);
        }
    }

    /**
     * Resolve command operands and canonical matcher mappings.
     *
     * @param list<WatchPath> $watchPaths
     * @return array{
     *     groups: array<string, array{recursive: bool, operands: list<string>}>,
     *     entries: list<array{prefix: string, base: string}>
     * }
     */
    protected function resolveWatchTargets(array $watchPaths): array
    {
        /** @var array<string, array{operand: string, recursive: bool, canonical: ?string}> $targets */
        $targets = [];
        $entries = [];

        foreach ($watchPaths as $watchPath) {
            $base = $watchPath->type === WatchPathType::File
                ? dirname($watchPath->path)
                : rtrim($watchPath->path, '/');
            $base = $base === '' ? '.' : $base;
            $literalPath = $base === '.' ? base_path() : base_path($base);
            $canonicalPath = realpath($literalPath);
            // A missing root keeps one literal operand/prefix because fswatch may activate it
            // as a symlink that a recursive parent process does not follow.
            $operand = $canonicalPath === false ? $literalPath : $canonicalPath;

            if (! isset($targets[$operand])) {
                $targets[$operand] = [
                    'operand' => $operand,
                    'recursive' => false,
                    'canonical' => $canonicalPath === false ? null : $canonicalPath,
                ];
            }

            $targets[$operand]['recursive'] = $targets[$operand]['recursive'] || $watchPath->recursive;

            $prefix = rtrim($operand, '/') . '/';
            $entryKey = $prefix . "\0" . $base;
            $entries[$entryKey] ??= ['prefix' => $prefix, 'base' => $base];
        }

        return [
            'groups' => $this->groupWatchTargets($targets),
            'entries' => array_values($entries),
        ];
    }

    /**
     * Group watch targets by the recursion depth their process requires.
     *
     * @param array<string, array{operand: string, recursive: bool, canonical: ?string}> $targets
     * @return array<string, array{recursive: bool, operands: list<string>}>
     */
    protected function groupWatchTargets(array $targets): array
    {
        if ($this->isDarwin()) {
            // FSEvents observes every operand recursively, so Darwin needs one unpruned process group.
            return ['all' => [
                'recursive' => array_any(
                    $targets,
                    static fn (array $target): bool => $target['recursive'],
                ),
                'operands' => array_column($targets, 'operand'),
            ]];
        }

        $recursiveTargets = array_filter(
            $targets,
            static fn (array $target): bool => $target['recursive'],
        );
        $shallowTargets = array_filter(
            $targets,
            static fn (array $target): bool => ! $target['recursive'],
        );
        $shallowOperands = [];

        foreach ($shallowTargets as $shallowTarget) {
            $contained = false;

            foreach ($recursiveTargets as $recursiveTarget) {
                if (
                    $shallowTarget['canonical'] !== null
                    && $recursiveTarget['canonical'] !== null
                    && $this->isContainedBy($shallowTarget['canonical'], $recursiveTarget['canonical'])
                ) {
                    $contained = true;
                    break;
                }
            }

            if (! $contained) {
                $shallowOperands[] = $shallowTarget['operand'];
            }
        }

        $groups = [];

        if ($shallowOperands !== []) {
            $groups['shallow'] = ['recursive' => false, 'operands' => $shallowOperands];
        }

        if ($recursiveTargets !== []) {
            $groups['recursive'] = [
                'recursive' => true,
                'operands' => array_column($recursiveTargets, 'operand'),
            ];
        }

        return $groups;
    }

    /**
     * Determine whether a path is equal to or nested beneath another path.
     *
     * Both paths must be canonical because a literal path can escape its
     * lexical parent through a symlink.
     */
    protected function isContainedBy(string $path, string $parent): bool
    {
        $path = rtrim($path, '/') ?: '/';
        $parent = rtrim($parent, '/') ?: '/';

        if ($path === $parent) {
            return true;
        }

        $prefix = $parent === '/' ? '/' : $parent . '/';

        return str_starts_with($path, $prefix);
    }

    /**
     * Determine whether the active watch loop should stop.
     *
     * The state may change while hooked I/O yields to another coroutine.
     *
     * @phpstan-impure
     */
    protected function shouldStopWatching(Channel $channel): bool
    {
        return $this->isStopping() || $channel->isClosing();
    }

    /**
     * Build the fswatch command arguments.
     *
     * @param list<string> $operands
     * @return list<string>
     */
    protected function getCommand(array $operands, bool $recursive): array
    {
        $command = ['fswatch'];

        if (! $this->isDarwin()) {
            array_push($command, '-m', 'inotify_monitor');
        }

        array_push($command, '-0', '--format', '%p');

        if ($recursive) {
            $command[] = '-r';
        }

        array_push(
            $command,
            '--event',
            'Created',
            '--event',
            'Updated',
            '--event',
            'Removed',
            '--event',
            'Renamed',
        );

        return [...$command, ...$operands];
    }
}
