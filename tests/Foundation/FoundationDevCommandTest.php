<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Foundation\DevCommand;
use Hypervel\Foundation\DevCommandColor;
use Hypervel\Tests\TestCase;

class FoundationDevCommandTest extends TestCase
{
    public function testNameDefaultsToFirstWordOfCommand(): void
    {
        $command = new DevCommand('php artisan serve', []);

        $this->assertSame('php', $command->name());
    }

    public function testNameCanBeExplicitlySet(): void
    {
        $command = new DevCommand('php artisan serve', [], 'server');

        $this->assertSame('server', $command->name());
    }

    public function testToArrayReturnsCommandDetails(): void
    {
        $command = new DevCommand('php artisan serve', [], 'server');

        $this->assertSame([
            'command' => 'php artisan serve',
            'name' => 'server',
            'color' => null,
            'source' => [],
            'priority' => DevCommand::PRIORITY_USERLAND,
        ], $command->toArray());
    }

    public function testColorCanBeSet(): void
    {
        $command = new DevCommand('php artisan serve', [], 'server');
        $result = $command->color('#ff0000');

        $this->assertSame($command, $result);
        $this->assertSame('#ff0000', $command->toArray()['color']);
    }

    public function testBlueColor(): void
    {
        $command = new DevCommand('cmd', [], 'test');
        $result = $command->blue();

        $this->assertSame($command, $result);
        $this->assertSame(DevCommandColor::Blue->value, $command->toArray()['color']);
    }

    public function testPurpleColor(): void
    {
        $command = new DevCommand('cmd', [], 'test');
        $command->purple();

        $this->assertSame(DevCommandColor::Purple->value, $command->toArray()['color']);
    }

    public function testPinkColor(): void
    {
        $command = new DevCommand('cmd', [], 'test');
        $command->pink();

        $this->assertSame(DevCommandColor::Pink->value, $command->toArray()['color']);
    }

    public function testOrangeColor(): void
    {
        $command = new DevCommand('cmd', [], 'test');
        $command->orange();

        $this->assertSame(DevCommandColor::Orange->value, $command->toArray()['color']);
    }

    public function testGreenColor(): void
    {
        $command = new DevCommand('cmd', [], 'test');
        $command->green();

        $this->assertSame(DevCommandColor::Green->value, $command->toArray()['color']);
    }

    public function testYellowColor(): void
    {
        $command = new DevCommand('cmd', [], 'test');
        $command->yellow();

        $this->assertSame(DevCommandColor::Yellow->value, $command->toArray()['color']);
    }

    public function testColorMethodsAreFluent(): void
    {
        $command = new DevCommand('cmd', [], 'test');

        $this->assertSame($command, $command->blue());
        $this->assertSame($command, $command->purple());
        $this->assertSame($command, $command->pink());
        $this->assertSame($command, $command->orange());
        $this->assertSame($command, $command->green());
        $this->assertSame($command, $command->yellow());
    }
}
