<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Contracts\Broadcasting\Broadcaster;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Terminal;

#[AsCommand(name: 'channel:list')]
class ChannelListCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'channel:list';

    /**
     * The console command description.
     */
    protected string $description = 'List all registered private broadcast channels';

    /**
     * The terminal width resolver callback.
     */
    protected static ?Closure $terminalWidthResolver = null;

    /**
     * Execute the console command.
     */
    public function handle(Broadcaster $broadcaster): void
    {
        $channels = $broadcaster->getChannels();

        if (! $channels->count()) {
            $this->components->error("Your application doesn't have any private broadcasting channels.");

            return;
        }

        $this->displayChannels($channels);
    }

    /**
     * Display the channel information on the console.
     */
    protected function displayChannels(Collection $channels): void
    {
        $this->output->writeln($this->forCli($channels));
    }

    /**
     * Convert the given channels to regular CLI output.
     */
    protected function forCli(Collection $channels): array
    {
        $maxChannelName = $channels->keys()->max(function ($channelName) {
            return mb_strlen($channelName);
        });

        $terminalWidth = $this->getTerminalWidth();

        $channelCount = $this->determineChannelCountOutput($channels, $terminalWidth);

        /** @var Collection<int|string, string> $lines Widen from non-falsy-string for intentional blank-line formatting */
        $lines = $channels->map(function ($channel, $channelName) use ($maxChannelName, $terminalWidth) {
            $resolver = $channel instanceof Closure ? 'Closure' : $channel;

            $spaces = str_repeat(' ', max($maxChannelName + 6 - mb_strlen($channelName), 0));

            $dots = str_repeat('.', max(
                $terminalWidth - mb_strlen($channelName . $spaces . $resolver) - 6,
                0
            ));

            $dots = empty($dots) ? $dots : " {$dots}";

            return sprintf(
                '  <fg=blue;options=bold>%s</> %s<fg=white>%s</><fg=#6C7280>%s</>',
                $channelName,
                $spaces,
                $resolver,
                $dots,
            );
        })
            ->filter()
            ->sort();

        return $lines
            ->prepend('')
            ->push('')->push($channelCount)->push('')
            ->toArray();
    }

    /**
     * Determine and return the output for displaying the number of registered channels in the CLI output.
     */
    protected function determineChannelCountOutput(Collection $channels, int $terminalWidth): string
    {
        $channelCountText = 'Showing [' . $channels->count() . '] private channels';

        $offset = $terminalWidth - mb_strlen($channelCountText) - 2;

        $spaces = str_repeat(' ', $offset);

        return $spaces . '<fg=blue;options=bold>Showing [' . $channels->count() . '] private channels</>';
    }

    /**
     * Get the terminal width.
     */
    public static function getTerminalWidth(): int
    {
        return is_null(static::$terminalWidthResolver)
            ? (new Terminal)->getWidth()
            : call_user_func(static::$terminalWidthResolver);
    }

    /**
     * Set a callback that should be used when resolving the terminal width.
     */
    public static function resolveTerminalWidthUsing(?Closure $resolver): void
    {
        static::$terminalWidthResolver = $resolver;
    }

    /**
     * Flush the static state of the command.
     */
    public static function flushState(): void
    {
        static::$terminalWidthResolver = null;
    }
}
