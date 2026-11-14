<?php

use App\Domain\Exceptions\AbstractDomainException;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use App\Support\DomainExceptionMapper;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'correlation' => CorrelationIdMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Art. IV.1 + RF-13: mapeo único dominio -> HTTP y logging estructurado
        // de toda excepción de negocio con correlation_id y contexto.
        $exceptions->render(function (AbstractDomainException $exception, Request $request): Response {
            $mapping = DomainExceptionMapper::map($exception);
            $correlationId = CorrelationId::fromRequest($request);
            $context = $exception->context();

            Log::error('domain_exception', [
                'correlation_id' => $correlationId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'context' => $context,
                'product_id' => $context['product_id'] ?? null,
                'warehouse_id' => $context['warehouse_id'] ?? null,
            ]);

            return response()->json([
                'error' => $mapping['code'],
                'message' => $exception->getMessage(),
                'correlation_id' => $correlationId,
            ], $mapping['status'], $mapping['headers']);
        });
    })->create();
