<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Prompts;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Prompts\Argument;
use Hypervel\Tests\Mcp\TestCase;

class PromptTest extends TestCase
{
    public function testReturnsNoMetaByDefault(): void
    {
        $prompt = new class extends Prompt {
            /**
             * Return the prompt description.
             */
            public function description(): string
            {
                return 'Test prompt';
            }

            /**
             * Return the prompt content.
             */
            public function handle(): Response
            {
                return Response::text('Hello');
            }
        };

        $this->assertNull($prompt->meta());
        $this->assertArrayNotHasKey('_meta', $prompt->toArray());
    }

    public function testCanHaveCustomMeta(): void
    {
        $prompt = new class extends Prompt {
            protected ?array $meta = [
                'category' => 'greeting',
                'tags' => ['hello', 'welcome'],
            ];

            /**
             * Return the prompt description.
             */
            public function description(): string
            {
                return 'Test prompt';
            }

            /**
             * Return the prompt content.
             */
            public function handle(): Response
            {
                return Response::text('Hello');
            }
        };

        $this->assertSame([
            'category' => 'greeting',
            'tags' => ['hello', 'welcome'],
        ], $prompt->toArray()['_meta']);
    }

    public function testIncludesIconsInToArrayWhenDeclaredOnAPrompt(): void
    {
        $prompt = new class extends Prompt {
            /**
             * Return the prompt description.
             */
            public function description(): string
            {
                return 'Test prompt';
            }

            /**
             * Return the prompt content.
             */
            public function handle(): Response
            {
                return Response::text('Hello');
            }

            /**
             * Return the prompt icons.
             */
            public function icons(): array
            {
                return [new Icon('https://example.com/prompt.png', mimeType: 'image/png')];
            }
        };

        $this->assertSame([
            ['src' => 'https://example.com/prompt.png', 'mimeType' => 'image/png'],
        ], $prompt->toArray()['icons']);
    }

    public function testOmitsIconsInToArrayWhenNoneAreDeclaredOnAPrompt(): void
    {
        $prompt = new class extends Prompt {
            /**
             * Return the prompt description.
             */
            public function description(): string
            {
                return 'Test prompt';
            }

            /**
             * Return the prompt content.
             */
            public function handle(): Response
            {
                return Response::text('Hello');
            }
        };

        $this->assertArrayNotHasKey('icons', $prompt->toArray());
    }

    public function testIncludesMetaInArrayRepresentationWithOtherFields(): void
    {
        $prompt = new class extends Prompt {
            protected string $name = 'greet';

            protected string $title = 'Greeting Prompt';

            protected string $description = 'A friendly greeting';

            protected ?array $meta = [
                'version' => '1.0',
            ];

            /**
             * Return the prompt content.
             */
            public function handle(): Response
            {
                return Response::text('Hello');
            }

            /**
             * Return the prompt arguments.
             */
            public function arguments(): array
            {
                return [
                    new Argument('name', 'User name', true),
                ];
            }
        };

        $array = $prompt->toArray();

        $this->assertSame('greet', $array['name']);
        $this->assertSame('Greeting Prompt', $array['title']);
        $this->assertSame('A friendly greeting', $array['description']);
        $this->assertSame(['version' => '1.0'], $array['_meta']);
        $this->assertCount(1, $array['arguments']);
    }
}
