<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Resources;

use Hypervel\Mcp\Enums\Role;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Annotations\Audience;
use Hypervel\Mcp\Server\Annotations\LastModified;
use Hypervel\Mcp\Server\Annotations\Priority;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ResourceAnnotationsTest extends TestCase
{
    public function testReturnsNoAnnotationObjectWhenNoAnnotationsArePresent(): void
    {
        $resource = new class extends Resource {
            public function description(): string
            {
                return 'A test resource.';
            }

            public function handle(): Response
            {
                return Response::text('Test content');
            }
        };

        $this->assertSame([], $resource->annotations());
        $this->assertArrayNotHasKey('annotations', $resource->toArray());
    }

    public function testIncludesAudienceAnnotationInResource(): void
    {
        $resource = new #[Audience([Role::User, Role::Assistant])] class extends Resource {
            public function description(): string
            {
                return 'A test resource.';
            }

            public function handle(): Response
            {
                return Response::text('Test content');
            }
        };

        $annotations = $resource->annotations();
        $this->assertArrayHasKey('audience', $annotations);
        $this->assertSame(['user', 'assistant'], $annotations['audience']);
    }

    public function testIncludesPriorityAnnotationInResource(): void
    {
        $resource = new #[Priority(0.8)] class extends Resource {
            public function description(): string
            {
                return 'A test resource.';
            }

            public function handle(): Response
            {
                return Response::text('Test content');
            }
        };

        $annotations = $resource->annotations();
        $this->assertArrayHasKey('priority', $annotations);
        $this->assertSame(0.8, $annotations['priority']);
    }

    public function testIncludesTheLastModifiedAnnotationInResource(): void
    {
        $resource = new #[LastModified('2025-01-12T15:00:58Z')] class extends Resource {
            public function description(): string
            {
                return 'A test resource.';
            }

            public function handle(): Response
            {
                return Response::text('Test content');
            }
        };

        $annotations = $resource->annotations();
        $this->assertArrayHasKey('lastModified', $annotations);
        $this->assertSame('2025-01-12T15:00:58Z', $annotations['lastModified']);
    }

    public function testIncludesMultipleAnnotationsInResource(): void
    {
        $resource = new #[Audience(Role::User)] #[Priority(1.0)] #[LastModified('2025-01-12T15:00:58Z')] class extends Resource {
            public function description(): string
            {
                return 'A test resource.';
            }

            public function handle(): Response
            {
                return Response::text('Test content');
            }
        };

        $annotations = $resource->annotations();
        $this->assertArrayHasKey('audience', $annotations);
        $this->assertArrayHasKey('priority', $annotations);
        $this->assertArrayHasKey('lastModified', $annotations);
        $this->assertSame(['user'], $annotations['audience']);
        $this->assertSame(1.0, $annotations['priority']);
        $this->assertSame('2025-01-12T15:00:58Z', $annotations['lastModified']);
    }

    public function testIncludesAnnotationsInResourceToArray(): void
    {
        $resource = new #[Audience(Role::User)] #[Priority(0.5)] class extends Resource {
            public function description(): string
            {
                return 'A test resource.';
            }

            public function handle(): Response
            {
                return Response::text('Test content');
            }
        };

        $array = $resource->toArray();
        $this->assertArrayHasKey('annotations', $array);
        $this->assertArrayHasKey('audience', $array['annotations']);
        $this->assertArrayHasKey('priority', $array['annotations']);
        $this->assertSame(['user'], $array['annotations']['audience']);
        $this->assertSame(0.5, $array['annotations']['priority']);
    }

    public function testValidatesAudienceRoles(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All values of ' . Audience::class . ' attributes must be instances of ' . Role::class);
        $resource = new #[Audience(['invalid_role'])] class extends Resource {
            public function description(): string
            {
                return 'Test';
            }

            public function handle(): Response
            {
                return Response::text('Test');
            }
        };
        $resource->annotations();
    }

    public function testValidatesPriorityRangeMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $resource = new #[Priority(-0.1)] class extends Resource {
            public function description(): string
            {
                return 'Test';
            }

            public function handle(): Response
            {
                return Response::text('Test');
            }
        };
        $resource->annotations();
    }

    public function testValidatesPriorityRangeMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $resource = new #[Priority(1.1)] class extends Resource {
            public function description(): string
            {
                return 'Test';
            }

            public function handle(): Response
            {
                return Response::text('Test');
            }
        };
        $resource->annotations();
    }

    public function testRejectsNanPriority(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Priority(NAN);
    }

    #[DataProvider('invalidLastModifiedValues')]
    public function testValidatesLastModifiedFormat(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LastModified($value);
    }

    /**
     * Provide malformed or unsupported resource timestamps.
     */
    public static function invalidLastModifiedValues(): array
    {
        return [
            'malformed' => ['not-a-valid-timestamp'],
            'date only' => ['2025-01-12'],
            'missing timezone' => ['2025-01-12T15:00:58'],
            'relative' => ['yesterday'],
            'impossible date' => ['2025-02-30T12:00:00Z'],
            'trailing newline' => ["2025-01-12T15:00:58Z\n"],
        ];
    }

    public function testAcceptsValidIso8601FormatsForLastModified(): void
    {
        $resource = new #[LastModified('2025-01-12T15:00:58+00:00')] class extends Resource {
            public function description(): string
            {
                return 'Test';
            }

            public function handle(): Response
            {
                return Response::text('Test');
            }
        };

        $annotations = $resource->annotations();
        $this->assertSame('2025-01-12T15:00:58+00:00', $annotations['lastModified']);
    }

    public function testPreservesFractionalSecondsAndTimezoneOffset(): void
    {
        $annotation = new LastModified('2024-02-29T15:00:58.123456789-05:30');

        $this->assertSame('2024-02-29T15:00:58.123456789-05:30', $annotation->value);
    }

    public function testAcceptsPriorityOfZero(): void
    {
        $resource = new #[Priority(0.0)] class extends Resource {
            public function description(): string
            {
                return 'Test';
            }

            public function handle(): Response
            {
                return Response::text('Test');
            }
        };

        $this->assertSame(0.0, $resource->annotations()['priority']);
    }

    public function testAcceptsPriorityOfOne(): void
    {
        $resource = new #[Priority(1.0)] class extends Resource {
            public function description(): string
            {
                return 'Test';
            }

            public function handle(): Response
            {
                return Response::text('Test');
            }
        };

        $this->assertSame(1.0, $resource->annotations()['priority']);
    }

    public function testAcceptsEmptyAudienceArray(): void
    {
        $resource = new #[Audience([])] class extends Resource {
            public function description(): string
            {
                return 'Test';
            }

            public function handle(): Response
            {
                return Response::text('Test');
            }
        };

        $this->assertSame([], $resource->annotations()['audience']);
    }

    public function testAcceptsOnlyUserRole(): void
    {
        $resource = new #[Audience(Role::User)] class extends Resource {
            public function description(): string
            {
                return 'Test';
            }

            public function handle(): Response
            {
                return Response::text('Test');
            }
        };

        $this->assertSame(['user'], $resource->annotations()['audience']);
    }

    public function testAcceptsOnlyAnAssistantRole(): void
    {
        $resource = new #[Audience(Role::Assistant)] class extends Resource {
            public function description(): string
            {
                return 'Test';
            }

            public function handle(): Response
            {
                return Response::text('Test');
            }
        };

        $this->assertSame(['assistant'], $resource->annotations()['audience']);
    }

    public function testRejectsToolAnnotationsOnResources(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Annotation [Hypervel\Mcp\Server\Tools\Annotations\IsReadOnly] cannot be used on');
        $resource = new #[IsReadOnly] class extends Resource {
            public function description(): string
            {
                return 'Test';
            }

            public function handle(): Response
            {
                return Response::text('Test');
            }
        };

        $resource->annotations();
    }
}
