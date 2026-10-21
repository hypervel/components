<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Feature\Body;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Saloon\Fixtures\Requests\MixedMultipartRequest;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;

// The other upstream cases in this file use fakes and are in tests/Saloon/Feature/Body/HasMultipartBodyTest.php.
class HasMultipartBodyTest extends TestCase
{
    use InteractsWithServer;

    protected int $serverPort = 19505;

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test server connection.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInteractsWithServer();
    }

    public function testCanSendARealMultipartRequestAndFilesAreSent(): void
    {
        $connector = new TestConnector($this->serverUrl());
        $request = new MixedMultipartRequest;

        $request->attach('name', 'Howdy');
        $request->attach('file', file_get_contents(__DIR__ . '/../../Fixtures/Howdy.txt'), 'hi.txt');

        $data = $connector->send($request)->json();

        $this->assertSame('Howdy', $data['name']);
        $this->assertSame('Hello World!' . PHP_EOL, $data['file_contents']);
    }

    public function testCanSendAnEmptyStringAsTheContents(): void
    {
        $connector = new TestConnector($this->serverUrl());
        $request = new MixedMultipartRequest;

        $request->attach('name', 'Howdy');
        $request->attach('file', '', 'hi.txt');

        $data = $connector->send($request)->json();

        $this->assertSame('Howdy', $data['name']);
        $this->assertSame('', $data['file_contents']);
    }

    public function testWithDataSendsFieldsAlongsideAnAttachedFile(): void
    {
        $connector = new TestConnector($this->serverUrl());
        $request = (new MixedMultipartRequest)
            ->attach('file', file_get_contents(__DIR__ . '/../../Fixtures/Howdy.txt'), 'hi.txt')
            ->withData([
                'name' => 'Howdy',
                'active' => true,
                'inactive' => false,
                'nothing' => null,
                'roles' => ['admin', 'editor'],
                'profile' => ['city' => 'London'],
            ]);

        $data = $connector->send($request)->json();

        $this->assertSame([
            'name' => 'Howdy',
            'active' => '1',
            'inactive' => '',
            'nothing' => '',
            'roles' => ['admin', 'editor'],
            'profile' => ['city' => 'London'],
        ], $data['fields']);
        $this->assertSame('Hello World!' . PHP_EOL, $data['file_contents']);
    }

    /**
     * Get the test server URL.
     */
    protected function serverUrl(): string
    {
        return sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort());
    }
}
