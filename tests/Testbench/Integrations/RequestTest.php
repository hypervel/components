<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
class RequestTest extends TestCase
{
    #[Override]
    protected function defineRoutes(Router $router): void
    {
        $router->get('hello', ['uses' => fn () => 'hello world']);
    }

    /**
     * Define web routes setup.
     */
    protected function defineWebRoutes(Router $router): void
    {
        $router->get('web/hello', ['middleware' => 'web', 'uses' => function () {
            $request = request()->merge(['name' => 'test-old-value']);
            $request->flash();

            return 'hello world';
        }]);
    }

    #[Test]
    public function itCanGetRequestInformation(): void
    {
        $this->call('GET', 'hello?foo=bar');

        $this->assertSame('http://localhost/hello?foo=bar', url()->full());
        $this->assertSame('http://localhost/hello', url()->current());
        $this->assertSame(['foo' => 'bar'], request()->all());
    }

    #[Test]
    public function itFlashesRequestValues(): void
    {
        $this->call('GET', 'web/hello');

        $this->assertSame('test-old-value', old('name'));
    }
}
