<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * RF-18: correlación de extremo a extremo.
 * Usa el valor del header X-Correlation-ID si llega válido, o genera un UUID.
 * El middleware de correlación (T-13) lo propaga a la respuesta.
 */
final class CorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    /** Atributo de petición donde el middleware comparte el id resuelto. */
    public const ATTRIBUTE = 'correlation_id';

    /**
     * Guarda el id resuelto en la petición para que las capas posteriores
     * (FormRequest → Command → movimiento persistido) reutilicen el MISMO
     * valor que se ecoa en la respuesta (RF-18/RF-13).
     */
    public static function remember(Request $request, string $correlationId): void
    {
        $request->attributes->set(self::ATTRIBUTE, $correlationId);
    }

    /**
     * Recupera el id resuelto por el middleware, o genera uno nuevo si la
     * petición no pasó por `correlation` (RF-18: el sistema siempre produce).
     */
    public static function fromAttributes(Request $request): string
    {
        $stored = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($stored) && Str::isUuid($stored)) {
            return $stored;
        }

        return (string) Str::uuid();
    }

    public static function fromRequest(Request $request): string
    {
        $value = $request->header(self::HEADER);

        if (is_string($value) && Str::isUuid($value)) {
            return $value;
        }

        return (string) Str::uuid();
    }
}
