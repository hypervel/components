<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Terminal as SymfonyTerminal;

class Terminal
{
    /**
     * The initial TTY mode.
     */
    protected ?string $initialTtyMode = null;

    /**
     * Whether the terminal supports true color.
     */
    protected static ?bool $trueColorSupport = null;

    /**
     * The terminal's foreground color as an RGB array.
     *
     * @var null|array{int, int, int}
     */
    protected static ?array $foregroundColor = null;

    /**
     * The terminal's background color as an RGB array.
     *
     * @var null|array{int, int, int}
     */
    protected static ?array $backgroundColor = null;

    /**
     * The Symfony Terminal instance.
     */
    protected SymfonyTerminal $terminal;

    /**
     * Create a new Terminal instance.
     */
    public function __construct()
    {
        $this->terminal = new SymfonyTerminal;
    }

    /**
     * Read a line from the terminal.
     */
    public function read(): string
    {
        $input = @fread(STDIN, 1024);

        if ($input === false) {
            throw new RuntimeException('Unable to read input from the terminal.');
        }

        if ($input === '' && feof(STDIN)) {
            throw new RuntimeException('The terminal input stream has closed.');
        }

        return $input;
    }

    /**
     * Set the TTY mode.
     */
    public function setTty(string $mode): void
    {
        $this->initialTtyMode ??= $this->exec('stty -g');

        $this->exec("stty {$mode}");
    }

    /**
     * Restore the initial TTY mode.
     */
    public function restoreTty(): void
    {
        if (isset($this->initialTtyMode)) {
            $this->exec("stty {$this->initialTtyMode}");

            $this->initialTtyMode = null;
        }
    }

    /**
     * Get the number of columns in the terminal.
     */
    public function cols(): int
    {
        return $this->terminal->getWidth();
    }

    /**
     * Get the number of lines in the terminal.
     */
    public function lines(): int
    {
        return $this->terminal->getHeight();
    }

    /**
     * (Re)initialize the terminal dimensions.
     */
    public function initDimensions(): void
    {
        (new ReflectionClass($this->terminal))
            ->getMethod('initDimensions')
            ->invoke($this->terminal);
    }

    /**
     * Exit the interactive session.
     */
    public function exit(): void
    {
        exit(1);
    }

    /**
     * Execute the given command and return the output.
     */
    protected function exec(string $command): string
    {
        return $this->execWithInput($command);
    }

    /**
     * Execute the given command with an optional input stream.
     *
     * @param null|resource $input
     */
    protected function execWithInput(string $command, $input = null): string
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        if ($input !== null) {
            $descriptors[0] = $input;
        }

        $process = proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to create process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0 || $stdout === false) {
            throw new RuntimeException(trim($stderr ?: "Unknown error (code: {$code})"), $code);
        }

        return $stdout;
    }

    /**
     * Determine if the terminal supports true color (24-bit).
     */
    public function supportsTrueColor(): bool
    {
        return static::$trueColorSupport ??= in_array(getenv('COLORTERM'), ['truecolor', '24bit'], true);
    }

    /**
     * Get the terminal's foreground color as an RGB array.
     *
     * @return array{int, int, int}
     */
    public function foregroundColor(): array
    {
        if (static::$foregroundColor === null) {
            $this->queryColors();
        }

        return static::$foregroundColor;
    }

    /**
     * Get the terminal's background color as an RGB array.
     *
     * @return array{int, int, int}
     */
    public function backgroundColor(): array
    {
        if (static::$backgroundColor === null) {
            $this->queryColors();
        }

        return static::$backgroundColor;
    }

    /**
     * Query the terminal for foreground and background colors in a single shot.
     */
    protected function queryColors(): void
    {
        $tty = $this->openTty();

        if ($tty === false) {
            static::$foregroundColor = [204, 204, 204];
            static::$backgroundColor = [0, 0, 0];

            return;
        }

        $savedTtyMode = null;
        $response = '';

        try {
            $savedTtyMode = trim($this->execWithInput('stty -g', $tty));
            $this->execWithInput('stty raw -echo min 0 time 1', $tty);

            $query = "\e]10;?\e\\\e]11;?\e\\";
            $written = @fwrite($tty, $query);

            if ($written !== strlen($query) || ! fflush($tty)) {
                throw new RuntimeException('Unable to query terminal colors.');
            }

            $response = @fread($tty, 200);

            if ($response === false) {
                throw new RuntimeException('Unable to read terminal colors.');
            }
        } finally {
            try {
                if ($savedTtyMode !== null) {
                    $this->execWithInput("stty {$savedTtyMode}", $tty);
                }
            } finally {
                fclose($tty);
            }
        }

        preg_match_all('/rgb:([0-9a-f]+)\/([0-9a-f]+)\/([0-9a-f]+)/i', $response, $matches, PREG_SET_ORDER);

        $parse = fn (array $m) => [
            (int) (hexdec($m[1]) / (strlen($m[1]) === 4 ? 257 : 1)),
            (int) (hexdec($m[2]) / (strlen($m[2]) === 4 ? 257 : 1)),
            (int) (hexdec($m[3]) / (strlen($m[3]) === 4 ? 257 : 1)),
        ];

        static::$foregroundColor = isset($matches[0]) ? $parse($matches[0]) : [204, 204, 204];
        static::$backgroundColor = isset($matches[1]) ? $parse($matches[1]) : [0, 0, 0];
    }

    /**
     * Open the controlling terminal.
     *
     * @return false|resource
     */
    protected function openTty()
    {
        return @fopen('/dev/tty', 'r+');
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$trueColorSupport = null;
        static::$foregroundColor = null;
        static::$backgroundColor = null;
    }
}
