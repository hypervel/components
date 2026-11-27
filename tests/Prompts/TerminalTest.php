<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Terminal;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithEnvironmentVariable;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Process\Process;

class TerminalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Terminal::flushState();
    }

    public function testReadThrowsWhenInputReachesEndOfFile(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = sprintf(
            'require %s; try { (new %s)->read(); } catch (RuntimeException $exception) { fwrite(STDOUT, $exception->getMessage()); }',
            var_export($autoload, true),
            Terminal::class,
        );
        $process = proc_open([
            PHP_BINARY,
            '-r',
            $code,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $errorOutput ?: 'The terminal read subprocess failed.');
        $this->assertSame('The terminal input stream has closed.', $output);
    }

    public function testColorQueryUsesOneOwnedTtyAndRestoresIt(): void
    {
        $terminal = new QueryingTerminalFixture;

        $this->assertSame([255, 0, 128], $terminal->foregroundColor());
        $this->assertSame([0, 255, 0], $terminal->backgroundColor());
        $this->assertSame([
            'stty -g',
            'stty raw -echo min 0 time 1',
            'stty saved-mode',
        ], $terminal->commands);
        $this->assertCount(1, array_unique($terminal->inputResourceIds));
        $this->assertFalse(is_resource($terminal->tty));
    }

    public function testUnavailableTtyUsesFallbackColorsWithoutQueryingTerminal(): void
    {
        $terminal = new UnavailableTtyTerminalFixture;

        $this->assertSame([204, 204, 204], $terminal->foregroundColor());
        $this->assertSame([0, 0, 0], $terminal->backgroundColor());
        $this->assertSame([], $terminal->commands);
    }

    #[DataProvider('redirectedStreams')]
    public function testSizesTheTerminalShowingOutput(string $redirection): void
    {
        $process = Process::fromShellCommandline('stty rows 30 cols 70 && "${:PHP}" "${:FIXTURE}" ' . $redirection, timeout: 10);
        $process->setPty(true);
        $process->mustRun(env: [
            'PHP' => PHP_BINARY,
            'FIXTURE' => __DIR__ . '/Fixtures/terminal-size.php',
            'COLUMNS' => false,
            'LINES' => false,
        ]);

        $this->assertSame([70, 30], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function redirectedStreams(): array
    {
        return [
            'piped input' => ['< /dev/null'],
            // Process keeps stderr on a pipe in PTY mode, so stderr is pointed at the terminal first.
            'piped input and stdout' => ['2>&0 < /dev/null | cat'],
        ];
    }

    public function testUsesTheFallbackSizeWhenProcOpenIsDisabled(): void
    {
        $process = Process::fromShellCommandline('stty rows 30 cols 70 && "${:PHP}" -d disable_functions=proc_open "${:FIXTURE}"', timeout: 10);
        $process->setPty(true);
        $process->mustRun(env: [
            'PHP' => PHP_BINARY,
            'FIXTURE' => __DIR__ . '/Fixtures/terminal-size.php',
            'COLUMNS' => false,
            'LINES' => false,
        ]);

        $this->assertSame([80, 50], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testStreamOutputThatIsNotATerminalIsNotATty(): void
    {
        $output = new StreamOutput(fopen('php://memory', 'w'));

        $this->assertFalse((new Terminal)->isOutputTty($output));
    }

    #[WithEnvironmentVariable('COLUMNS')]
    #[WithEnvironmentVariable('LINES')]
    public function testInitializesTheTerminalDimensionsWithoutDeprecationNotices(): void
    {
        $deprecations = [];
        set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED);

        try {
            (new Terminal)->initDimensions();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
    }

    public function testOutputIsCheckedAtItsDestination(): void
    {
        // STDOUT is a terminal here, so outputs written elsewhere must not report it.
        $process = new Process([PHP_BINARY, __DIR__ . '/Fixtures/output-tty.php'], timeout: 10);
        $process->setPty(true);
        $process->mustRun();

        $this->assertSame([
            'console' => true,
            'artisan' => true,
            'memory' => false,
            'captured artisan' => false,
            'buffered' => false,
            'prompts buffer' => false,
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }
}

class QueryingTerminalFixture extends Terminal
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<int> */
    public array $inputResourceIds = [];

    /** @var false|resource */
    public $tty = false;

    /**
     * Execute the given command and return the output.
     *
     * @param null|resource $input
     */
    protected function execWithInput(string $command, $input = null): string
    {
        $this->commands[] = $command;

        if (is_resource($input)) {
            $this->inputResourceIds[] = get_resource_id($input);
        }

        return $command === 'stty -g' ? 'saved-mode' : '';
    }

    /**
     * Open the controlling terminal.
     *
     * @return resource
     */
    protected function openTty()
    {
        $query = "\e]10;?\e\\\e]11;?\e\\";
        $this->tty = fopen('php://temp', 'r+');
        fwrite($this->tty, str_repeat(' ', strlen($query)) . 'rgb:ffff/0000/8080rgb:0000/ffff/0000');
        rewind($this->tty);

        return $this->tty;
    }
}

class UnavailableTtyTerminalFixture extends QueryingTerminalFixture
{
    /**
     * Open the controlling terminal.
     */
    protected function openTty(): false
    {
        return false;
    }
}
