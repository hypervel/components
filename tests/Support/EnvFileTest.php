<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Env;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EnvFileTest extends TestCase
{
    protected string $tempDirectory;

    protected string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = ParallelTesting::tempDir('SupportEnvFileTest');
        (new Filesystem)->deleteDirectory($this->tempDirectory);
        mkdir($this->tempDirectory, 0777, true);

        $this->envPath = $this->tempDirectory . '/.env';
        file_put_contents($this->envPath, 'APP_NAME=old');
        chmod($this->envPath, 0640);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    public function testWriteVariableReplacesTheFileAndPreservesItsMode(): void
    {
        Env::writeVariable('APP_NAME', 'new', $this->envPath, overwrite: true);

        $this->assertSame('APP_NAME=new', file_get_contents($this->envPath));
        $this->assertSame(0640, fileperms($this->envPath) & 0777);
    }

    public function testWriteVariablesQuotesPunctuationOutsideTheAlphanumericRange(): void
    {
        Env::writeVariables(['APP_NAME' => 'name_with_underscore'], $this->envPath, overwrite: true);

        $this->assertSame('APP_NAME="name_with_underscore"', file_get_contents($this->envPath));
    }

    #[DataProvider('existingAssignmentProvider')]
    public function testWritersUpdateTheAssignmentTheLoaderUses(array $lines, string $value, bool $overwrite, array $expected): void
    {
        $writers = [
            function () use ($value, $overwrite): void {
                Env::writeVariable('APP_NAME', $value, $this->envPath, $overwrite);
            },
            function () use ($value, $overwrite): void {
                Env::writeVariables(['APP_NAME' => $value], $this->envPath, $overwrite);
            },
        ];

        foreach ($writers as $write) {
            file_put_contents($this->envPath, implode(PHP_EOL, $lines));

            $write();

            $this->assertSame(implode(PHP_EOL, $expected), file_get_contents($this->envPath));
        }
    }

    /**
     * Provide existing assignments and the file contents expected after writing APP_NAME.
     *
     * @return array<string, array{list<string>, string, bool, list<string>}>
     */
    public static function existingAssignmentProvider(): array
    {
        return [
            'spaced assignment is overwritten in place' => [
                ['APP_NAME = old', 'APP_ENV=local'],
                'new',
                true,
                ['APP_NAME=new', 'APP_ENV=local'],
            ],
            'spaced assignment is kept without overwrite' => [
                ['APP_NAME = old', 'APP_ENV=local'],
                'new',
                false,
                ['APP_NAME = old', 'APP_ENV=local'],
            ],
            'exported assignment keeps its prefix' => [
                ['export APP_NAME=old'],
                'new',
                true,
                ['export APP_NAME=new'],
            ],
            'quoted name' => [
                ['"APP_NAME"=old'],
                'new',
                true,
                ['APP_NAME=new'],
            ],
            'last duplicate is replaced' => [
                ['APP_NAME=new', 'APP_NAME=old'],
                'new',
                true,
                ['APP_NAME=new', 'APP_NAME=new'],
            ],
            'literal empty value is filled without overwrite' => [
                ['APP_NAME=""'],
                'new',
                false,
                ['APP_NAME=new'],
            ],
            'value with a reference is kept without overwrite' => [
                ['APP_NAME="${OTHER}"'],
                'new',
                false,
                ['APP_NAME="${OTHER}"'],
            ],
            'assignment-like text inside a multiline value' => [
                ['APP_CERT="-----BEGIN-----', 'APP_NAME=inside', '-----END-----"', 'DB_HOST=localhost'],
                'new',
                true,
                ['APP_CERT="-----BEGIN-----', 'APP_NAME=inside', '-----END-----"', 'APP_NAME=new', 'DB_HOST=localhost'],
            ],
            'multiline value is replaced entirely' => [
                ['APP_NAME="first', 'second"', 'APP_ENV=local'],
                'new',
                true,
                ['APP_NAME=new', 'APP_ENV=local'],
            ],
        ];
    }
}
