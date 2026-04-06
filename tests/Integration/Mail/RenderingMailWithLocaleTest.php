<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Mail\Mailable;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class RenderingMailWithLocaleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('app.locale', 'en');

        $app['view']->addLocation(__DIR__ . '/Fixtures');

        $app['translator']->setLoaded([
            '*' => [
                '*' => [
                    'en' => ['nom' => 'name'],
                    'es' => ['nom' => 'nombre'],
                ],
            ],
        ]);
    }

    public function testMailableRendersInDefaultLocale()
    {
        $mail = new RenderedTestMail;

        $this->assertStringContainsString('name', $mail->render());
    }

    public function testMailableRendersInSelectedLocale()
    {
        $mail = (new RenderedTestMail)->locale('es');

        $this->assertStringContainsString('nombre', $mail->render());
    }

    public function testMailableRendersInAppSelectedLocale()
    {
        $this->app->setLocale('es');

        $mail = new RenderedTestMail;

        $this->assertStringContainsString('nombre', $mail->render());
    }
}

class RenderedTestMail extends Mailable
{
    public function build(): static
    {
        return $this->view('view');
    }
}
