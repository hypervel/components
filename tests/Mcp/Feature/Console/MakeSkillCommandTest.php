<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Console;

use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class MakeSkillCommandTest extends TestCase
{
    #[TestWith(['ReleaseChecklist', 'Mcp/Skills/ReleaseChecklist.php', 'skills/release-checklist'])]
    #[TestWith(['Billing/Refunds', 'Mcp/Skills/Billing/Refunds.php', 'skills/refunds'])]
    #[TestWith(['Release_Checklist', 'Mcp/Skills/Release_Checklist.php', 'skills/release-checklist'])]
    public function testCreatesASkillClassAndASkillMdFile(string $name, string $class, string $directory): void
    {
        $this->artisan('make:mcp-skill', ['name' => $name])->assertExitCode(0)->run();

        $this->assertStringContainsString("return '{$directory}';", (string) file_get_contents(app_path($class)));
        $this->assertStringStartsWith(
            "---\nname: " . basename($directory) . "\n",
            (string) file_get_contents(resource_path("{$directory}/SKILL.md")),
        );
    }

    public function testKeepsAnExistingSkillMdUnlessForced(): void
    {
        $this->artisan('make:mcp-skill', ['name' => 'ReleaseChecklist'])->assertExitCode(0)->run();
        file_put_contents(resource_path('skills/release-checklist/SKILL.md'), 'edited');

        unlink(app_path('Mcp/Skills/ReleaseChecklist.php'));

        $this->artisan('make:mcp-skill', ['name' => 'ReleaseChecklist'])
            ->expectsOutputToContain('already exists')
            ->assertExitCode(0)
            ->run();

        $this->assertSame('edited', file_get_contents(resource_path('skills/release-checklist/SKILL.md')));
        $this->assertFileExists(app_path('Mcp/Skills/ReleaseChecklist.php'));

        $this->artisan('make:mcp-skill', ['name' => 'ReleaseChecklist', '--force' => true])->assertExitCode(0)->run();
        $this->assertNotSame('edited', file_get_contents(resource_path('skills/release-checklist/SKILL.md')));
    }

    public function testMayPublishCustomStubs(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'mcp-stubs',
            '--force' => true,
        ])->assertExitCode(0)->run();

        $this->assertFileExists(base_path('stubs/mcp-skill.stub'));
        $this->assertFileExists(base_path('stubs/mcp-skill.md.stub'));
    }

    public function testFailsWhenTheSkillNameIsLongerThan64Characters(): void
    {
        $this->artisan('make:mcp-skill', ['name' => str_repeat('a', 65)])
            ->expectsOutputToContain('must not be longer than 64 characters')
            ->assertFailed()
            ->run();

        $this->assertFileDoesNotExist(app_path('Mcp/Skills/' . str_repeat('a', 65) . '.php'));
    }

    public function testFailsWhenTheSkillNameNormalizesToEmpty(): void
    {
        $this->artisan('make:mcp-skill', ['name' => '___'])
            ->expectsOutputToContain('must contain at least one letter or number')
            ->assertFailed()
            ->run();

        $this->assertFileDoesNotExist(app_path('Mcp/Skills/___.php'));
        $this->assertFileDoesNotExist(resource_path('skills/SKILL.md'));
    }
}
