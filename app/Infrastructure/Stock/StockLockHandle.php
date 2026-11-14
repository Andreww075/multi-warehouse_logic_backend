<?php

declare(strict_types=1);

namespace App\Infrastructure\Stock;

use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Log;
use Predis\PredisException;

/**
 * Handle de un lock de stock adquirido (RF-06).
 *
 * `release()` implementa la semántica completa de RF-16:
 * - Si el lock expiró a mitad de proceso (proceso más lento que el TTL de
 *   propiedad), registra la degradación con contexto estructurado (RF-12/RF-13)
 *   y no toca el lock de otro proceso.
 * - La liberación comparada por token de propiedad es un no-op seguro cuando
 *   el lock ya no es nuestro.
 * - Si Redis no está disponible al liberar, NO se relanza el fallo: una venta
 *   ya completada no debe convertirse en 503; el lock expirará solo por TTL
 *   (defensa en profundidad: la integridad queda en el `FOR UPDATE`, Art. I.1/I.2).
 */
final class StockLockHandle
{
    public function __construct(
        private readonly Lock $lock,
        public readonly string $key,
    ) {}

    public function release(): void
    {
        try {
            if (! $this->lock->isOwnedByCurrentProcess()) {
                Log::warning('stock_lock_expired', ['lock_key' => $this->key]);
            }

            $this->lock->release();
        } catch (PredisException $failure) {
            Log::error('stock_lock_release_failed', [
                'lock_key' => $this->key,
                'reason' => $failure->getMessage(),
            ]);
        }
    }
}
