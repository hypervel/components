<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server\Prompts;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Mcp\Server\Prompts\Argument;
use Hypervel\Tests\TestCase;

class ArgumentTest extends TestCase
{
    public function testCreatesAnArgumentWithRequiredParameters(): void
    {
        $argument = new Argument(
            name: 'username',
            description: 'The username to authenticate with'
        );

        $this->assertSame('username', $argument->name);
        $this->assertSame('The username to authenticate with', $argument->description);
        $this->assertFalse($argument->required);
    }

    public function testCreatesAnArgumentWithAllParameters(): void
    {
        $argument = new Argument(
            name: 'password',
            description: 'The password to authenticate with',
            required: true
        );

        $this->assertSame('password', $argument->name);
        $this->assertSame('The password to authenticate with', $argument->description);
        $this->assertTrue($argument->required);
    }

    public function testConvertsToArrayCorrectly(): void
    {
        $argument = new Argument(
            name: 'api_key',
            description: 'The API key for authentication',
            required: true
        );

        $this->assertSame([
            'name' => 'api_key',
            'description' => 'The API key for authentication',
            'required' => true,
        ], $argument->toArray());
    }

    public function testConvertsOptionalArgumentToArrayCorrectly(): void
    {
        $argument = new Argument(
            name: 'format',
            description: 'The output format'
        );

        $this->assertSame([
            'name' => 'format',
            'description' => 'The output format',
            'required' => false,
        ], $argument->toArray());
    }

    public function testImplementsArrayableInterface(): void
    {
        $argument = new Argument(
            name: 'test',
            description: 'Test argument'
        );

        $this->assertInstanceOf(Arrayable::class, $argument);
    }
}
