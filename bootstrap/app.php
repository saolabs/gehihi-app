<?php

use App\Modules\AiProviders\Services\ProviderFailure;
use App\Modules\Identity\Http\WorkspaceSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Saola\Core\Http\Middleware\Next;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [WorkspaceSession::class]);
        $middleware->alias(['next' => Next::class]);
        $middleware->redirectGuestsTo(fn ($request) => '/?login=1&next='.urlencode('/'.$request->path()));
    })->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['api_key', 'api_keys', 'password', 'password_confirmation']);
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->is('api/*')) {
                $status = $e instanceof ModelNotFoundException ? 404 : ($e instanceof AuthenticationException ? 401 : ($e instanceof ProviderFailure ? 422 : ($e instanceof ValidationException ? 422
                    : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500))));

                return response()->json(['success' => false, 'message' => $status < 500 ? $e->getMessage() : 'Có lỗi xử lý. Hãy thử lại.', 'errors' => $e instanceof ValidationException ? $e->errors() : []], $status);
            }
        });
    })->create();
