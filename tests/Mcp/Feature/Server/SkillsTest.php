<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Server;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Attributes\Uri;
use Hypervel\Mcp\Server\Skill;
use Hypervel\Mcp\Server\Skills\DirectorySkill;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Tests\Mcp\Fixtures\ArrayTransport;
use Hypervel\Tests\Mcp\Fixtures\ExampleServer;
use Hypervel\Tests\Mcp\Fixtures\HouseStyleSkill;
use Hypervel\Tests\Mcp\Fixtures\LastLogLineResource;
use Hypervel\Tests\Mcp\Fixtures\ReleaseChecklistSkill;
use Hypervel\Tests\Mcp\Fixtures\SkillsServer;
use Hypervel\Tests\Mcp\Fixtures\TeamReleaseChecklistSkill;
use Hypervel\Tests\Mcp\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Tests\Mcp\Fixtures\protocolMeta;

class SkillsTest extends TestCase
{
    private ArrayTransport $transport;

    private SkillsServer $server;

    private string $directory;

    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new ArrayTransport;
        $this->server = new SkillsServer($this->transport);
        $this->server->start();

        $this->directory = __DIR__ . '/../../Fixtures/Skills/release-checklist';
        $this->temporary = ParallelTesting::tempDir('McpSkillsTest');

        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->temporary);
        $filesystem->ensureDirectoryExists($this->temporary);
    }

    protected function tearDown(): void
    {
        try {
            (new Filesystem)->deleteDirectory($this->temporary);
        } finally {
            parent::tearDown();
        }
    }

    public function testDeclaresTheSkillsExtensionAndTheResourcesCapability(): void
    {
        $capabilities = $this->skillRequest($this->server, $this->transport, 'server/discover')['result']['capabilities'];

        $this->assertArrayHasKey('resources', $capabilities);
        $this->assertArrayHasKey('io.modelcontextprotocol/skills', $capabilities['extensions']);

        $server = new ExampleServer($this->transport);
        $server->start();

        $this->assertArrayNotHasKey(
            'extensions',
            $this->skillRequest($server, $this->transport, 'server/discover')['result']['capabilities'],
        );
    }

    public function testListsSkillsWithTheirFrontmatterAndAManifestOfEveryFile(): void
    {
        $result = $this->skillRequest($this->server, $this->transport, 'skills/list')['result'];

        $this->assertArrayHasKey('resultType', $result);
        $this->assertArrayHasKey('ttlMs', $result);
        $this->assertArrayHasKey('cacheScope', $result);
        $this->assertSame([[
            'uri' => 'skill://release-checklist/SKILL.md',
            'frontmatter' => [
                'name' => 'release-checklist',
                'description' => 'Prepare a release using the bundled checklist.',
                'license' => 'MIT',
                'compatibility' => 'Requires a project with a changelog.',
                'metadata' => ['version' => '1.0'],
                'allowed-tools' => 'Read',
            ],
            'resources' => [
                [
                    'uri' => 'skill://release-checklist/SKILL.md',
                    'digest' => 'sha256:' . hash_file('sha256', $this->directory . '/SKILL.md'),
                    'size' => filesize($this->directory . '/SKILL.md'),
                ],
                [
                    'uri' => 'skill://release-checklist/references/checklist.md',
                    'digest' => 'sha256:' . hash_file('sha256', $this->directory . '/references/checklist.md'),
                    'size' => filesize($this->directory . '/references/checklist.md'),
                ],
            ],
        ]], $result['skills']);
    }

    public function testGetsASkillByTheUriOfItsSkillMd(): void
    {
        $list = $this->skillRequest($this->server, $this->transport, 'skills/list')['result']['skills'][0];
        $get = $this->skillRequest($this->server, $this->transport, 'skills/get', ['uri' => 'skill://release-checklist/SKILL.md']);

        $this->assertSame($list, $get['result']['skill']);

        $this->assertSame(
            -32602,
            $this->skillRequest($this->server, $this->transport, 'skills/get', ['uri' => 'skill://missing/SKILL.md'])['error']['code'],
        );
        $this->assertSame(
            -32602,
            $this->skillRequest($this->server, $this->transport, 'skills/get', ['uri' => ['skill://release-checklist/SKILL.md']])['error']['code'],
        );
    }

    public function testListsOnlySkillMdAsAResourceButReadsEverySkillFile(): void
    {
        $resources = array_values(array_filter(
            $this->skillRequest($this->server, $this->transport, 'resources/list')['result']['resources'],
            fn (array $resource): bool => str_starts_with($resource['uri'], 'skill://'),
        ));

        $this->assertCount(1, $resources);
        $this->assertSame('skill://release-checklist/SKILL.md', $resources[0]['uri']);
        $this->assertSame('release-checklist', $resources[0]['name']);
        $this->assertSame('release-checklist', $resources[0]['title']);
        $this->assertSame('Prepare a release using the bundled checklist.', $resources[0]['description']);
        $this->assertSame('text/markdown', $resources[0]['mimeType']);

        $read = $this->skillRequest($this->server, $this->transport, 'resources/read', ['uri' => 'skill://release-checklist/references/checklist.md']);

        $this->assertSame(
            file_get_contents($this->directory . '/references/checklist.md'),
            $read['result']['contents'][0]['text'],
        );
    }

    public function testHidesSkillsThatShouldNotRegister(): void
    {
        config(['mcp.testing.house-style' => false]);

        $server = new class($this->transport) extends ExampleServer {
            protected array $skills = [HouseStyleSkill::class, ReleaseChecklistSkill::class];
        };
        $server->start();

        $this->assertSame(
            ['skill://release-checklist/SKILL.md'],
            array_column($this->skillRequest($server, $this->transport, 'skills/list')['result']['skills'], 'uri'),
        );
        $this->assertSame(
            -32602,
            $this->skillRequest($server, $this->transport, 'skills/get', ['uri' => 'skill://house-style/SKILL.md'])['error']['code'],
        );
        $this->assertSame(
            -32602,
            $this->skillRequest($server, $this->transport, 'resources/read', ['uri' => 'skill://house-style/SKILL.md'])['error']['code'],
        );
        $this->assertNotContains(
            'skill://house-style/SKILL.md',
            array_column($this->skillRequest($server, $this->transport, 'resources/list')['result']['resources'], 'uri'),
        );
    }

    public function testRegistersASkillFolderByItsPath(): void
    {
        $server = new class($this->transport) extends ExampleServer {
            protected array $skills = [__DIR__ . '/../../Fixtures/Skills/house-style', ReleaseChecklistSkill::class];
        };
        $server->start();

        $this->assertSame(
            [
                'skill://house-style/SKILL.md',
                'skill://release-checklist/SKILL.md',
            ],
            array_column($this->skillRequest($server, $this->transport, 'skills/list')['result']['skills'], 'uri'),
        );
        $this->assertStringContainsString(
            'Be brief.',
            $this->skillRequest($server, $this->transport, 'resources/read', ['uri' => 'skill://house-style/SKILL.md'])['result']['contents'][0]['text'],
        );
    }

    public function testUsesTheUriAttributeAsTheSkillUri(): void
    {
        $skill = new TeamReleaseChecklistSkill;

        $this->assertSame('skill://team/release-checklist/SKILL.md', $skill->uri());
        $this->assertSame([
            'skill://team/release-checklist/SKILL.md',
            'skill://team/release-checklist/references/checklist.md',
        ], array_column($skill->toArray()['resources'], 'uri'));
    }

    public function testResolvesRelativePathsFromTheResourcesPathAndSkipsDotfiles(): void
    {
        $files = new Filesystem;
        $files->copyDirectory($this->directory, resource_path('skills/release-checklist'));
        $files->put(resource_path('skills/release-checklist/.env'), 'SECRET=1');

        try {
            $skill = new DirectorySkill('skills/release-checklist');

            $this->assertSame(resource_path('skills/release-checklist'), $skill->directory());
            $this->assertSame(['SKILL.md', 'references/checklist.md'], $skill->files());
        } finally {
            $files->deleteDirectory(resource_path('skills'));
        }
    }

    #[DataProvider('invalidFrontmatterProvider')]
    public function testRequiresAValidNameAndADescriptionInTheFrontmatter(string $frontmatter, string $message): void
    {
        $skill = $this->temporarySkill($this->temporary . '/demo', $frontmatter);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $skill->frontmatter();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidFrontmatterProvider(): array
    {
        return [
            'missing description' => ['name: demo', 'The description field is required.'],
            'empty description' => ["name: demo\ndescription: ''", 'The description field is required.'],
            'long description' => ["name: demo\ndescription: " . str_repeat('a', 1025), 'The description field must not be greater than 1024 characters.'],
            'uppercase name' => ["name: Demo\ndescription: A demo skill.", 'The name field format is invalid.'],
            'path in name' => ["name: ../demo\ndescription: A demo skill.", 'The name field format is invalid.'],
            'long name' => ['name: ' . str_repeat('a', 65) . "\ndescription: A demo skill.", 'The name field must not be greater than 64 characters.'],
            'long compatibility' => ["name: demo\ndescription: A demo skill.\ncompatibility: " . str_repeat('a', 501), 'The compatibility field must not be greater than 500 characters.'],
            'nested metadata' => ["name: demo\ndescription: A demo skill.\nmetadata:\n  owner:\n    team: core", 'The metadata.owner field must be a string.'],
            'scalar frontmatter' => ['demo', 'The frontmatter must be a YAML mapping.'],
            'invalid yaml' => ["name: [demo\ndescription: A demo skill.", 'has an invalid SKILL.md frontmatter'],
            'php object tag' => ["name: demo\ndescription: A demo skill.\nobject: !php/object 'O:8:\"stdClass\":0:{}'", 'has an invalid SKILL.md frontmatter'],
            'name not matching the directory' => ["name: other\ndescription: A demo skill.", 'must match its directory'],
        ];
    }

    public function testRequiresASkillMdWithAFrontmatterBlock(): void
    {
        $skill = $this->temporarySkill($this->temporary . '/demo');
        file_put_contents($this->temporary . '/demo/SKILL.md', "# Demo\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The SKILL.md file must start with a YAML frontmatter block.');

        $skill->frontmatter();
    }

    public function testKeepsFrontmatterDatesAsWritten(): void
    {
        $skill = $this->temporarySkill($this->temporary . '/demo', "name: demo\ndescription: A demo skill.\nupdated: 2024-01-01\nreleased: 2024-01-01T10:30:00+02:00");
        $frontmatter = $skill->frontmatter();

        $this->assertSame('2024-01-01', $frontmatter['updated']);
        $this->assertSame('2024-01-01T10:30:00+02:00', $frontmatter['released']);
    }

    public function testRequiresTheUriAttributeToEndWithTheSkillNameAndSkillMd(): void
    {
        $this->temporarySkill($this->temporary . '/demo');

        $missingFile = new #[Uri('skill://team/demo')] class($this->temporary . '/demo') extends DirectorySkill {};
        $otherName = new #[Uri('skill://team/other/SKILL.md')] class($this->temporary . '/demo') extends DirectorySkill {};

        try {
            $missingFile->uri();
            $this->fail('Expected InvalidArgumentException for missing SKILL.md suffix.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must end with [/demo/SKILL.md]', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must end with [/demo/SKILL.md]');

        $otherName->uri();
    }

    public function testReportsAndSkipsInvalidSkillsWithoutAffectingOtherResources(): void
    {
        Exceptions::fake();

        $this->temporarySkill($this->temporary . '/broken', "name: Broken\ndescription: A broken skill.");
        $this->temporarySkill($this->temporary . '/first/demo');
        $this->temporarySkill($this->temporary . '/second/demo');

        $server = new class($this->transport) extends ExampleServer {
            public array $skills = [];
        };
        $server->skills = [
            $this->temporary . '/broken',
            $this->temporary . '/missing',
            $this->temporary . '/first/demo',
            $this->temporary . '/second/demo',
            ReleaseChecklistSkill::class,
        ];
        $server->start();

        $resource = new LastLogLineResource;

        $this->assertSame(
            ['skill://demo/SKILL.md', 'skill://release-checklist/SKILL.md'],
            array_column($this->skillRequest($server, $this->transport, 'skills/list')['result']['skills'], 'uri'),
        );

        $listedResources = array_column($this->skillRequest($server, $this->transport, 'resources/list')['result']['resources'], 'uri');
        $this->assertContains($resource->uri(), $listedResources);
        $this->assertContains('skill://demo/SKILL.md', $listedResources);
        $this->assertContains('skill://release-checklist/SKILL.md', $listedResources);

        $this->assertArrayHasKey('result', $this->skillRequest($server, $this->transport, 'resources/read', ['uri' => $resource->uri()]));
        $this->assertStringContainsString(
            '# Demo',
            $this->skillRequest($server, $this->transport, 'resources/read', ['uri' => 'skill://demo/SKILL.md'])['result']['contents'][0]['text'],
        );

        Exceptions::assertReported(fn (InvalidArgumentException $e): bool => str_contains($e->getMessage(), 'The name field format is invalid.'));
        Exceptions::assertReported(fn (InvalidArgumentException $e): bool => str_contains($e->getMessage(), 'does not exist'));
        Exceptions::assertReported(fn (InvalidArgumentException $e): bool => str_contains($e->getMessage(), 'is already registered'));
    }

    public function testValidatesSkillsListPagination(): void
    {
        $server = new class($this->transport) extends ExampleServer {
            public array $skills = [HouseStyleSkill::class, ReleaseChecklistSkill::class];
        };
        $server->start();

        $first = $this->skillRequest($server, $this->transport, 'skills/list', ['per_page' => 1])['result'];
        $second = $this->skillRequest($server, $this->transport, 'skills/list', ['per_page' => 1, 'cursor' => $first['nextCursor']])['result'];

        $this->assertSame(['skill://house-style/SKILL.md'], array_column($first['skills'], 'uri'));
        $this->assertSame(['skill://release-checklist/SKILL.md'], array_column($second['skills'], 'uri'));
        $this->assertArrayNotHasKey('nextCursor', $second);
        $this->assertSame(-32602, $this->skillRequest($server, $this->transport, 'skills/list', ['per_page' => 0])['error']['code']);
        $this->assertSame(-32602, $this->skillRequest($server, $this->transport, 'skills/list', ['per_page' => '1'])['error']['code']);
        $this->assertSame(-32602, $this->skillRequest($server, $this->transport, 'skills/list', ['cursor' => 1])['error']['code']);
    }

    public function testReadsSkillFilesByTheirEncodedOrUnencodedUri(): void
    {
        $this->temporarySkill($this->temporary . '/demo');
        (new Filesystem)->ensureDirectoryExists($this->temporary . '/demo/ref');
        file_put_contents($this->temporary . '/demo/ref/a (1).md', 'Notes');
        $png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89";
        file_put_contents($this->temporary . '/demo/logo.png', $png);

        $server = new class($this->transport) extends ExampleServer {
            public array $skills = [];
        };
        $server->skills = [$this->temporary . '/demo'];
        $server->start();

        $manifest = array_column($this->skillRequest($server, $this->transport, 'skills/get', ['uri' => 'skill://demo/SKILL.md'])['result']['skill']['resources'], 'uri');
        $binary = $this->skillRequest($server, $this->transport, 'resources/read', ['uri' => 'skill://demo/logo.png'])['result']['contents'][0];

        $this->assertSame(['skill://demo/SKILL.md', 'skill://demo/logo.png', 'skill://demo/ref/a%20%281%29.md'], $manifest);
        $this->assertSame('Notes', $this->skillRequest($server, $this->transport, 'resources/read', ['uri' => 'skill://demo/ref/a%20%281%29.md'])['result']['contents'][0]['text']);
        $this->assertSame('Notes', $this->skillRequest($server, $this->transport, 'resources/read', ['uri' => 'skill://demo/ref/a%20(1).md'])['result']['contents'][0]['text']);
        $this->assertSame(base64_encode($png), $binary['blob']);
        $this->assertSame('image/png', $binary['mimeType']);
    }

    public function testNeverServesFilesOutsideTheSkillDirectory(): void
    {
        $skill = $this->temporarySkill($this->temporary . '/demo');
        file_put_contents($this->temporary . '/secret.txt', 'secret');
        symlink($this->temporary . '/secret.txt', $this->temporary . '/demo/linked.txt');
        symlink($this->temporary, $this->temporary . '/demo/parent');

        $server = new class($this->transport) extends ExampleServer {
            public array $skills = [];
        };
        $server->skills = [$skill];
        $server->start();

        $this->assertSame(['SKILL.md'], $skill->files());

        try {
            $skill->read('../secret.txt');
            $this->fail('Expected InvalidArgumentException for parent path traversal.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('is not readable', $exception->getMessage());
        }

        try {
            $skill->read('linked.txt');
            $this->fail('Expected InvalidArgumentException for symlink outside skill directory.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('is not readable', $exception->getMessage());
        }

        foreach (['skill://demo/linked.txt', 'skill://demo/../secret.txt', 'skill://demo/parent/secret.txt'] as $uri) {
            $this->assertSame(-32602, $this->skillRequest($server, $this->transport, 'resources/read', ['uri' => $uri])['error']['code']);
        }
    }

    public function testRefusesSkillsOverTheFileCountLimit(): void
    {
        $skill = $this->temporarySkill($this->temporary . '/demo');

        foreach (range(1, Skill::MAX_FILES) as $index) {
            touch($this->temporary . "/demo/{$index}.txt");
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain more than 512 files or 16 MiB');

        $skill->files();
    }

    public function testRefusesSkillsOverTheSizeLimit(): void
    {
        $skill = $this->temporarySkill($this->temporary . '/demo');

        $handle = fopen($this->temporary . '/demo/large.bin', 'w');
        $this->assertIsResource($handle);
        ftruncate($handle, Skill::MAX_SIZE + 1);
        fclose($handle);

        try {
            $skill->files();
            $this->fail('Expected InvalidArgumentException for oversized skill directory.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must not contain more than 512 files or 16 MiB', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not readable');

        $skill->read('large.bin');
    }

    public function testSkipsDotDirectories(): void
    {
        $skill = $this->temporarySkill($this->temporary . '/demo');
        (new Filesystem)->ensureDirectoryExists($this->temporary . '/demo/.git');
        file_put_contents($this->temporary . '/demo/.git/config', 'secret');

        $this->assertSame(['SKILL.md'], $skill->files());
    }

    public function testRefreshesFrontmatterAcrossRequestsForAutoSingletonedSkillClasses(): void
    {
        $this->temporarySkill($this->temporary . '/demo', "name: demo\ndescription: First version.");
        config(['mcp.testing.dynamic-skill-path' => $this->temporary . '/demo']);

        $server = new class($this->transport) extends ExampleServer {
            public array $skills = [DynamicPathSkillStub::class];
        };
        $server->start();

        $first = $this->skillRequest($server, $this->transport, 'skills/list')['result']['skills'][0];
        $this->assertSame('First version.', $first['frontmatter']['description']);

        file_put_contents(
            $this->temporary . '/demo/SKILL.md',
            "---\nname: demo\ndescription: Updated version.\n---\n\n# Demo\n",
        );

        $second = $this->skillRequest($server, $this->transport, 'skills/list')['result']['skills'][0];
        $this->assertSame('Updated version.', $second['frontmatter']['description']);
    }

    /**
     * Send a modern JSON-RPC request to the server and decode the last reply.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function skillRequest(Server $server, ArrayTransport $transport, string $method, array $params = []): array
    {
        $server->handle((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => ['_meta' => protocolMeta(), ...$params],
        ]));

        return json_decode((string) end($transport->sent), true);
    }

    /**
     * Create a temporary directory-backed skill with the given frontmatter.
     */
    private function temporarySkill(string $directory, string $frontmatter = "name: demo\ndescription: A demo skill."): Skill
    {
        (new Filesystem)->ensureDirectoryExists($directory);
        file_put_contents($directory . '/SKILL.md', "---\n{$frontmatter}\n---\n\n# Demo\n");

        return new DirectorySkill($directory);
    }
}

class DynamicPathSkillStub extends Skill
{
    public function path(): string
    {
        return config()->string('mcp.testing.dynamic-skill-path');
    }
}
