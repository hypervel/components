<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Exception;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Tests\TestCase;
use Hypervel\View\ViewException;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ViewExceptionTest extends TestCase
{
    public function testRenderForwardsThePreviousExceptionResult(): void
    {
        $request = Request::create('/');
        $view = m::mock(ViewContract::class);
        $responsable = new ViewExceptionResponsable;
        $response = new Response('hypervel');
        $symfonyResponse = new SymfonyResponse('symfony');

        foreach (['rendered', ['message' => 'rendered'], $view, $responsable, $response, $symfonyResponse, false, null] as $result) {
            $exception = $this->viewException(new RenderableViewExceptionPrevious($result));

            $this->assertSame($result, $exception->render($request));
        }
    }

    public function testRenderReturnsNullWhenThePreviousExceptionCannotRender(): void
    {
        $this->assertNull($this->viewException(new Exception)->render(Request::create('/')));
    }

    public function testReportForwardsThePreviousExceptionResult(): void
    {
        foreach ([true, false, null] as $result) {
            $this->assertSame(
                $result,
                $this->viewException(new ReportableViewExceptionPrevious($result))->report()
            );
        }
    }

    public function testReportReturnsFalseWhenThePreviousExceptionCannotReport(): void
    {
        $this->assertFalse($this->viewException(new Exception)->report());
    }

    protected function viewException(Exception $previous): ViewException
    {
        return new ViewException('View failed.', 0, E_ERROR, __FILE__, __LINE__, $previous);
    }
}

class RenderableViewExceptionPrevious extends Exception
{
    public function __construct(protected mixed $result)
    {
        parent::__construct();
    }

    public function render(Request $request): mixed
    {
        return $this->result;
    }
}

class ReportableViewExceptionPrevious extends Exception
{
    public function __construct(protected ?bool $result)
    {
        parent::__construct();
    }

    public function report(): ?bool
    {
        return $this->result;
    }
}

class ViewExceptionResponsable implements Responsable
{
    public function toResponse(Request $request): SymfonyResponse
    {
        return new SymfonyResponse;
    }
}
