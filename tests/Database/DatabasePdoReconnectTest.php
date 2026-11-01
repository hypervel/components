<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;

#[RequiresOperatingSystem('Linux')]
#[RequiresPhpExtension('pdo_mysql')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
#[RequiresPhpExtension('sockets')]
class DatabasePdoReconnectTest extends TestCase
{
    #[TestWith([false], 'native streams')]
    #[TestWith([true], 'hooked streams')]
    public function testStatementRetriesAfterTheServerResetsItsSocket(bool $hooked): void
    {
        // @TODO: Remove this skip once Swoole socket writes honor PHP_STREAM_FLAG_SUPPRESS_ERRORS,
        // allowing mysqlnd to report its normal lost-connection exception after a socket reset.
        if ($hooked && version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Swoole emits a transport notice before PDO can report the lost connection.');
        }

        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/Fixtures/ReconnectAfterSocketReset.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            $hooked ? 'yes' : 'no',
        ]);
        $process->setTimeout(10);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(
            ['result' => true, 'reconnects' => 1],
            json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
