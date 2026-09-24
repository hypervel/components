<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

class RequestMakeCommandTest extends TestCase
{
    protected array $files = [
        'app/Http/Requests/FooRequest.php',
        'app/Http/Requests/ValidationRule.php',
    ];

    public function testItCanGenerateRequestFile(): void
    {
        $this->artisan('make:request', ['name' => 'FooRequest'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Requests;',
            'use Hypervel\Foundation\Http\FormRequest;',
            'class FooRequest extends FormRequest',
        ], 'app/Http/Requests/FooRequest.php');
    }

    public function testItCanGenerateRequestFileNamedValidationRule(): void
    {
        $this->artisan('make:request', ['name' => 'ValidationRule'])
            ->assertExitCode(0);

        $this->assertPhpFileCompiles('app/Http/Requests/ValidationRule.php');
    }
}
