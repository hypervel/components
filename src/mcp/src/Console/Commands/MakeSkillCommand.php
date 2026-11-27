<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Console\Commands;

use Hypervel\Console\GeneratorCommand;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'make:mcp-skill',
    description: 'Create a new MCP skill class and SKILL.md file'
)]
class MakeSkillCommand extends GeneratorCommand
{
    protected string $type = 'Skill';

    /**
     * Generate the skill class and its SKILL.md file.
     */
    public function handle(): bool|int
    {
        $skillName = $this->skillName();

        if ($skillName === '') {
            $this->fail("The skill name [{$this->getNameInput()}] must contain at least one letter or number.");
        }

        if (strlen($skillName) > 64) {
            $this->fail("The skill name [{$skillName}] must not be longer than 64 characters.");
        }

        $result = parent::handle();

        if ($result === false) {
            return false;
        }

        $this->createSkillFile();

        return $result;
    }

    /**
     * Create the skill's SKILL.md file if missing or forced.
     */
    protected function createSkillFile(): void
    {
        $name = $this->skillName();
        $path = resource_path("skills/{$name}/SKILL.md");

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->warn("Skill file [{$path}] already exists.");

            return;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, str_replace(
            ['{{ name }}', '{{ title }}'],
            [$name, Str::headline(class_basename($this->getNameInput()))],
            $this->files->get($this->resolveStub('mcp-skill.md.stub')),
        ));

        $this->components->info("Skill file [{$path}] created successfully.");
    }

    /**
     * Get the skill class stub.
     */
    protected function getStub(): string
    {
        return $this->resolveStub('mcp-skill.stub');
    }

    /**
     * Get the default skill namespace.
     */
    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return "{$rootNamespace}\\Mcp\\Skills";
    }

    /**
     * Build the skill class with its directory path.
     */
    protected function buildClass(string $name): string
    {
        return str_replace('{{ path }}', "skills/{$this->skillName()}", parent::buildClass($name));
    }

    /**
     * Derive the slugged skill name from the class input.
     */
    protected function skillName(): string
    {
        return Str::slug(Str::kebab(class_basename($this->getNameInput())));
    }

    /**
     * Resolve a published or package stub.
     */
    protected function resolveStub(string $stub): string
    {
        return file_exists($customPath = $this->getHypervel()->basePath("stubs/{$stub}"))
            ? $customPath
            : __DIR__ . "/../../../stubs/{$stub}";
    }

    /**
     * Get the generation options.
     *
     * @return array<int, array<int, int|string>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class and SKILL.md even if the skill already exists'],
        ];
    }
}
