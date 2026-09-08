<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

class ResourceMakeCommandTest extends TestCase
{
    protected array $files = [
        'app/Http/Resources/FooResource.php',
        'app/Http/Resources/FooResourceCollection.php',
    ];

    public function testItCanGenerateResourceFile(): void
    {
        $this->artisan('make:resource', ['name' => 'FooResource'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Resources;',
            'use Hypervel\Http\Resources\Json\JsonResource;',
            'class FooResource extends JsonResource',
        ], 'app/Http/Resources/FooResource.php');
    }

    public function testItCanGenerateResourceCollectionFile(): void
    {
        $this->artisan('make:resource', ['name' => 'FooResourceCollection', '--collection' => true])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Resources;',
            'use Hypervel\Http\Resources\Json\ResourceCollection;',
            'class FooResourceCollection extends ResourceCollection',
        ], 'app/Http/Resources/FooResourceCollection.php');
    }

    public function testItCanGenerateJsonApiResourceFile(): void
    {
        $this->artisan('make:resource', ['name' => 'FooResource', '--json-api' => true])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Http\Resources;',
            'use Hypervel\Http\Resources\JsonApi\JsonApiResource;',
            'class FooResource extends JsonApiResource',
        ], 'app/Http/Resources/FooResource.php');

        $this->assertFileNotContains([
            'use Hypervel\Http\Request;',
        ], 'app/Http/Resources/FooResource.php');
    }
}
