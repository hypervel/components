<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Env;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;

class EnvFileTest extends TestCase
{
    protected string $tempDirectory;

    protected string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = ParallelTesting::tempDir('SupportEnvFileTest');
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
}
