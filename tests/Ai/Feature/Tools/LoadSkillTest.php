<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Tools;

use Hypervel\Ai\Contracts\HasSkills;
use Hypervel\Ai\Skills\Skill;
use Hypervel\Ai\Tools\LoadSkill;
use Hypervel\Ai\Tools\Request;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Ai\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;

class LoadSkillTest extends TestCase
{
    protected string $skills;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->skills = ParallelTesting::tempDir('LoadSkillTest');
        File::deleteDirectory($this->skills);
        File::ensureDirectoryExists($this->skills);
    }

    /**
     * Clean up the test files.
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->skills);

        parent::tearDown();
    }

    public function testItListsEveryDiscoveredSkillAlphabeticallyInItsDescription(): void
    {
        $this->skill('pdf', "name: pdf\ndescription: 'Extract PDF text. Use when: handling PDFs.'");
        $this->skill('audit', "name: audit\ndescription: Review invoices.");

        $this->assertSame(
            "Load a skill's instructions before performing a task matching the skill's description. Pass a path to read one of the skill's bundled files instead.\n\n"
            . "Available skills:\n"
            . "- audit: Review invoices.\n"
            . '- pdf: Extract PDF text. Use when: handling PDFs.',
            (new LoadSkill([$this->skills]))->description(),
        );
    }

    public function testItReturnsASkillBodyWithoutItsFrontmatterAndListsTheBundledFiles(): void
    {
        $path = $this->skill('pdf', "name: pdf\ndescription: Extract PDF text.", '# Extracting' . "\n\n" . 'Run the script.');

        $this->bundle($path, 'references/spec.md', 'Spec.');
        $this->bundle($path, 'scripts/extract.py', 'print(1)');

        $result = (new LoadSkill([$this->skills]))->handle(new Request(['name' => 'pdf']));

        $this->assertStringContainsString('<skill_content name="pdf">', $result);
        $this->assertStringContainsString('# Extracting', $result);
        $this->assertStringNotContainsString('description: Extract PDF text.', $result);
        $this->assertStringContainsString("Run the script.\n\n<skill_resources>\nreferences/spec.md\nscripts/extract.py\n</skill_resources>", $result);
    }

    public function testItReadsAFileBundledWithASkill(): void
    {
        $path = $this->skill('pdf', "name: pdf\ndescription: Extract PDF text.");
        $this->bundle($path, 'references/spec.md', 'The spec.');

        $this->assertSame('The spec.', (new LoadSkill([$this->skills]))->handle(new Request(['name' => 'pdf', 'path' => 'references/spec.md'])));
    }

    public function testItWillNotReadABinaryOrOversizedBundledFile(): void
    {
        $path = $this->skill('pdf', "name: pdf\ndescription: Extract PDF text.");
        $this->bundle($path, 'assets/logo.png', "\x89PNG\x00\xff");
        $this->bundle($path, 'references/huge.md', str_repeat('a', 256 * 1024 + 1));

        $tool = new LoadSkill([$this->skills]);

        $this->assertSame('File [assets/logo.png] appears to be binary and cannot be read as text.', $tool->handle(new Request(['name' => 'pdf', 'path' => 'assets/logo.png'])));
        $this->assertSame('File [references/huge.md] is too large to read inline.', $tool->handle(new Request(['name' => 'pdf', 'path' => 'references/huge.md'])));
    }

    public function testItWillNotReadABinaryOrOversizedInMemoryFile(): void
    {
        $tool = new LoadSkill([new Skill('pdf', 'Extract PDF text.', 'Body.', [
            'logo.png' => "\x89PNG\x00\xff",
            'huge.md' => str_repeat('a', 256 * 1024 + 1),
        ])]);

        $this->assertSame('File [logo.png] appears to be binary and cannot be read as text.', $tool->handle(new Request(['name' => 'pdf', 'path' => 'logo.png'])));
        $this->assertSame('File [huge.md] is too large to read inline.', $tool->handle(new Request(['name' => 'pdf', 'path' => 'huge.md'])));
    }

    public function testItWillNotReadAFileOutsideOfTheSkillDirectory(): void
    {
        $this->skill('pdf', "name: pdf\ndescription: Extract PDF text.");
        $this->skill('secret', "name: secret\ndescription: Secrets.", 'Do not leak.');

        $this->assertSame('File [../secret/SKILL.md] is not bundled with skill [pdf].', (new LoadSkill([$this->skills]))->handle(new Request(['name' => 'pdf', 'path' => '../secret/SKILL.md'])));
    }

    public function testItSkipsASkillWithoutADescriptionButLoadsOneWhoseNameDoesNotMatchItsDirectory(): void
    {
        $this->skill('no-description', 'name: no-description');
        $this->skill('renamed', "name: actual-name\ndescription: Still loads.");

        $this->assertSame(['actual-name'], (new LoadSkill([$this->skills]))->schema(new JsonSchemaTypeFactory)['name']->toArray()['enum']);
    }

    public function testItResolvesObjectAndClosureSourcesAndLetsTheEarliestSourceWinACollision(): void
    {
        $this->skill('pdf', "name: pdf\ndescription: From the directory.");

        $tool = new LoadSkill([
            new Skill('invoices', 'Review invoices.', 'Check the totals.', ['notes.md' => 'Notes.']),
            fn () => [new Skill('pdf', 'From the closure.', 'Closure body.')],
            $this->skills,
        ]);

        $this->assertStringContainsString('- pdf: From the closure.', $tool->description());
        $this->assertStringNotContainsString('From the directory.', $tool->description());
        $this->assertStringContainsString('Check the totals.', $tool->handle(new Request(['name' => 'invoices'])));
        $this->assertStringContainsString("<skill_resources>\nnotes.md\n</skill_resources>", $tool->handle(new Request(['name' => 'invoices'])));
        $this->assertSame('Notes.', $tool->handle(new Request(['name' => 'invoices', 'path' => 'notes.md'])));
    }

    public function testItLetsTheEarliestSourceWinACollisionBetweenKeyedClosureResults(): void
    {
        $tool = new LoadSkill([
            fn () => collect([new Skill('pdf', 'From the first closure.', 'First.')])->keyBy('name'),
            fn () => collect([new Skill('pdf', 'From the second closure.', 'Second.')])->keyBy('name'),
        ]);

        $this->assertStringContainsString('- pdf: From the first closure.', $tool->description());
    }

    public function testItKeepsANumericSkillNameAStringInTheSchema(): void
    {
        $tool = new LoadSkill([new Skill('123', 'Numeric.', 'Body.')]);

        $this->assertSame(['123'], $tool->schema(new JsonSchemaTypeFactory)['name']->toArray()['enum']);
        $this->assertStringContainsString('<skill_content name="123">', $tool->handle(new Request(['name' => '123'])));
    }

    public function testItReportsAnUnknownSkillAndAnEmptySource(): void
    {
        $this->assertSame('Skill [missing] does not exist.', (new LoadSkill([$this->skills]))->handle(new Request(['name' => 'missing'])));
        $this->assertArrayNotHasKey('enum', (new LoadSkill([$this->skills]))->schema(new JsonSchemaTypeFactory)['name']->toArray());
    }

    public function testItDiscoversSkillsInTheResourcesDirectoryByDefault(): void
    {
        File::ensureDirectoryExists(resource_path('skills/pdf'));
        File::put(resource_path('skills/pdf/SKILL.md'), "---\nname: pdf\ndescription: Extract PDF text.\n---\n\nBody.");

        try {
            $this->assertStringContainsString('- pdf: Extract PDF text.', (new LoadSkill)->description());
        } finally {
            File::deleteDirectory(resource_path('skills'));
        }
    }

    public function testItParsesFrontmatterAsYaml(): void
    {
        $this->skill('comment', "name: comment # trailing comment\ndescription: Strips comments.");
        $this->skill('multiline', "name: multiline\ndescription: \"Spans\n  two lines.\"");
        $this->skill('escaped', "name: escaped\ndescription: 'The team''s invoices.'");
        $this->skill('folded', "name: folded\ndescription: > # a comment\n  Folded text.");

        $description = (new LoadSkill([$this->skills]))->description();
        $this->assertStringContainsString('- comment: Strips comments.', $description);
        $this->assertStringContainsString('- multiline: Spans two lines.', $description);
        $this->assertStringContainsString("- escaped: The team's invoices.", $description);
        $this->assertStringContainsString('- folded: Folded text.', $description);
    }

    public function testItThrowsOnFrontmatterThatIsNotValidYaml(): void
    {
        $path = $this->skill('pdf', "name: pdf\ndescription: Use when: handling PDFs.");

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage(realpath($path) . '/SKILL.md');

        (new LoadSkill([$this->skills]))->description();
    }

    public function testItListsNoResourcesForASkillPointedAtAMissingDirectory(): void
    {
        $tool = new LoadSkill([new Skill('ghost', 'Gone.', 'Body.', path: $this->skills . '/missing')]);
        $result = $tool->handle(new Request(['name' => 'ghost']));

        $this->assertStringContainsString('Body.', $result);
        $this->assertStringNotContainsString('<skill_resources>', $result);
    }

    public function testItSquishesEverySkillDescriptionIntoOneCatalogLine(): void
    {
        $this->skill('pdf', "description: >\n  Extract PDF text.\n\n  Use when handling PDFs.\nname: pdf");
        $tool = new LoadSkill([$this->skills, new Skill('audit', "Review invoices.\n  Flag duplicates.", 'Body.')]);

        $this->assertStringContainsString("- audit: Review invoices. Flag duplicates.\n", $tool->description());
        $this->assertStringEndsWith('- pdf: Extract PDF text. Use when handling PDFs.', $tool->description());
    }

    public function testAnAgentWithoutSkillsDoesNotReceiveTheLoadSkillTool(): void
    {
        $agent = new class implements HasSkills {
            /**
             * Get the agent's skills.
             */
            public function skills(): iterable
            {
                return [];
            }
        };

        $this->assertSame([], LoadSkill::mergeInto([], $agent));
    }

    public function testItCachesOnlyConfiguredDirectoriesUntilFlushed(): void
    {
        config(['ai.skills.cached_paths' => [$this->skills . '/fixed/']]);
        $this->skill('fixed/pdf', "name: pdf\ndescription: Original fixed.");
        $this->skill('dynamic/audit', "name: audit\ndescription: Original dynamic.");
        $sources = [$this->skills . '/fixed', $this->skills . '/dynamic'];

        $description = (new LoadSkill($sources))->description();
        $this->assertStringContainsString('- pdf: Original fixed.', $description);
        $this->assertStringContainsString('- audit: Original dynamic.', $description);

        $this->skill('fixed/pdf', "name: pdf\ndescription: Updated fixed.");
        $this->skill('dynamic/audit', "name: audit\ndescription: Updated dynamic.");

        $description = (new LoadSkill($sources))->description();
        $this->assertStringContainsString('- pdf: Original fixed.', $description);
        $this->assertStringContainsString('- audit: Updated dynamic.', $description);

        LoadSkill::flushState();

        $this->assertStringContainsString('- pdf: Updated fixed.', (new LoadSkill($sources))->description());
    }

    /**
     * Create a skill directory with YAML frontmatter.
     */
    protected function skill(string $directory, string $frontmatter, string $body = 'Body.'): string
    {
        File::ensureDirectoryExists($path = $this->skills . '/' . $directory);
        File::put($path . '/SKILL.md', "---\n{$frontmatter}\n---\n\n{$body}");

        return $path;
    }

    /**
     * Write a file bundled with a skill.
     */
    protected function bundle(string $path, string $file, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path . '/' . $file));

        File::put($path . '/' . $file, $contents);
    }
}
