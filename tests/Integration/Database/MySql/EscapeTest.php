<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MySql;

use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;

#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_mysql')]
class EscapeTest extends MySqlTestCase
{
    public function testEscapeInt(): void
    {
        $database = $this->app->make('db');

        $this->assertSame('42', $database->escape(42));
        $this->assertSame('-6', $database->escape(-6));
    }

    public function testEscapeFloat(): void
    {
        $database = $this->app->make('db');

        $this->assertSame('3.14159', $database->escape(3.14159));
        $this->assertSame('-3.14159', $database->escape(-3.14159));
    }

    public function testEscapeBool(): void
    {
        $database = $this->app->make('db');

        $this->assertSame('1', $database->escape(true));
        $this->assertSame('0', $database->escape(false));
    }

    public function testEscapeNull(): void
    {
        $database = $this->app->make('db');

        $this->assertSame('null', $database->escape(null));
        $this->assertSame('null', $database->escape(null, true));
    }

    public function testEscapeBinary(): void
    {
        $this->assertSame("x'dead00beef'", $this->app->make('db')->escape(hex2bin('dead00beef'), true));
    }

    public function testEscapeString(): void
    {
        $database = $this->app->make('db');

        $this->assertSame("'2147483647'", $database->escape('2147483647'));
        $this->assertSame("'true'", $database->escape('true'));
        $this->assertSame("'false'", $database->escape('false'));
        $this->assertSame("'null'", $database->escape('null'));
        $this->assertSame("'Hello\\'World'", $database->escape("Hello'World"));
    }

    public function testEscapeStringInvalidUtf8(): void
    {
        $this->expectException(RuntimeException::class);

        $this->app->make('db')->escape("I am hiding an invalid \x80 utf-8 continuation byte");
    }

    public function testEscapeStringNullByte(): void
    {
        $this->expectException(RuntimeException::class);

        $this->app->make('db')->escape("I am hiding a \00 byte");
    }

    public function testEscapeArray(): void
    {
        $this->expectException(RuntimeException::class);

        $this->app->make('db')->escape(['a', 'b']);
    }
}
