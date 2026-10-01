<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Response;
use Hypervel\Workbench\Workbench;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CatchDefaultRoute
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): SymfonyResponse $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $workbench = Workbench::config();

        if ($request->decodedPath() === '/' && ! \is_null($workbench['user']) && \is_null($request->user($workbench['guard']))) {
            return redirect('/_workbench');
        }

        $response = $next($request);

        if ($request->decodedPath() !== '/') {
            return $response;
        }

        if (property_exists($response, 'exception') && ! \is_null($response->exception) && $response->exception instanceof NotFoundHttpException) {
            if ($workbench['start'] !== '/') {
                return redirect($workbench['start']);
            }
            if (
                ($workbench['install'] === true && $workbench['welcome'] !== false)
                || ($workbench['install'] === false && $workbench['welcome'] === true)
            ) {
                return Response::view('welcome');
            }
        }

        return $response;
    }
}
