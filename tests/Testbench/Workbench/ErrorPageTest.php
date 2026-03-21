<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Workbench;

use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
class ErrorPageTest extends TestCase
{
    use WithWorkbench;

    #[Test]
    #[WithConfig('app.debug', true)]
    public function itCanResolveExceptionPage()
    {
        $this->get('/failed')
            ->assertInternalServerError()
            ->assertSee('RuntimeException')
            ->assertSee('Bad route!');
    }

    #[Test]
    #[WithConfig('app.debug', true)]
    public function itCanResolveExceptionWithoutExceptionHandling()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bad route!');

        $this->withoutExceptionHandling()
            ->get('/failed');
    }

    #[Test]
    public function itCanResolveExceptionPageWithoutEnablingDebugMode()
    {
        $this->get('/failed')
            ->assertInternalServerError()
            ->assertSee('500')
            ->assertSee('Server Error');
    }

    #[Test]
    public function itCanResolveExceptionWithoutExceptionHandlingWithoutEnablingDebugMode()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bad route!');

        $this->withoutExceptionHandling()
            ->get('/failed');
    }

    #[Test]
    #[WithConfig('app.debug', true)]
    public function itCanResolveExceptionPageUsingJsonRequest()
    {
        $this->getJson('/api/failed')
            ->assertInternalServerError()
            ->assertSee('RuntimeException')
            ->assertSee('Bad route!');
    }

    #[Test]
    #[WithConfig('app.debug', true)]
    public function itCanResolveExceptionUsingJsonRequestWithoutExceptionHandling()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bad route!');

        $this->withoutExceptionHandling()
            ->get('/failed');
    }
}
