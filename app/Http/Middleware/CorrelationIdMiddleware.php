<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * RF-18: correlación de extremo a extremo.
 *
 * Resuelve el correlation_id de la petición (header entrante válido o UUID
 * nuevo), lo inyecta en el contexto de log de la petición (RF-13) y lo ecoa
 * en la respuesta para que el caller pueda seguir la cadena entre servicios.
 */
final class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = CorrelationId::fromRequest($request);

        CorrelationId::remember($request, $correlationId);

        Log::withContext(['correlation_id' => $correlationId]);

        $response = $next($request);

        $response->headers->set(CorrelationId::HEADER, $correlationId);

        return $response;
    }
}
