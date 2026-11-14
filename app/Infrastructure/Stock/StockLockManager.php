<?php

declare(strict_types=1);

namespace App\Infrastructure\Stock;

use App\Domain\Exceptions\StockInfrastructureUnavailableException;
use App\Domain\Exceptions\StockLockAcquisitionException;
use Illuminate\Cache\RedisLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Redis\Factory;
use Predis\PredisException;

/**
 * M3 / RF-06, RF-09, RF-15, RF-16 · Plan P-01, P-02, P-03.
 *
 * Encapsula la adquisición del lock distribuido Redis usando el mecanismo de
 * Illuminate (`RedisLock`: token de propiedad + liberación comparada), creado
 * directamente sobre la conexión Redis y no sobre el store de cache, por dos
 * razones justificadas:
 * 1. La clave del lock queda EXACTAMENTE como la fija el Art. I.1
 *    (`lock:stock:{sku}:{warehouse_id}`), sin prefijos de cache.
 * 2. `Cache::store()` devuelve un `Repository` que reenvía `lock()` por `__call`
 *    sin tipado — incompatible con PHPStan nivel 7 sin supresiones.
 *
 * Separación estricta de duraciones (Spec v1.1, resuelve A2):
 * - espera bloqueante de adquisición: hasta 5 s (contención → esperar, RF-09);
 * - TTL de propiedad: 5 s (lock de un proceso colgado expira solo).
 *
 * El Action no conoce Redis: solo este contrato.
 */
final class StockLockManager
{
    public const DEFAULT_WAIT_SECONDS = 5;

    public const DEFAULT_TTL_SECONDS = 5;

    public function __construct(
        private readonly Factory $redis,
        private readonly int $blockingWaitSeconds = self::DEFAULT_WAIT_SECONDS,
        private readonly int $ownershipTtlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {}

    /**
     * Clave canónica del Art. I.1 de la Constitución.
     */
    public static function key(string $sku, string $warehouseId): string
    {
        return sprintf('lock:stock:%s:%s', $sku, $warehouseId);
    }

    /**
     * Adquiere el lock de forma bloqueante. Excepciones:
     * - contención no resuelta dentro de la espera → RF-09 (503);
     * - Redis inalcanzable → RF-15 (503).
     *
     * @throws StockLockAcquisitionException
     * @throws StockInfrastructureUnavailableException
     */
    public function acquire(string $sku, string $warehouseId): StockLockHandle
    {
        $key = self::key($sku, $warehouseId);
        $context = ['sku' => $sku, 'warehouse_id' => $warehouseId, 'lock_key' => $key];

        try {
            $lock = new RedisLock($this->redis->connection(), $key, $this->ownershipTtlSeconds);
            $lock->block($this->blockingWaitSeconds);
        } catch (LockTimeoutException) {
            throw new StockLockAcquisitionException(sprintf(
                'lock %s no adquirido tras %d s de espera bloqueante',
                $key,
                $this->blockingWaitSeconds,
            ))->withContext($context);
        } catch (PredisException $failure) {
            throw new StockInfrastructureUnavailableException(sprintf(
                'redis no disponible para %s: %s',
                $key,
                $failure->getMessage(),
            ))->withContext($context);
        }

        return new StockLockHandle($lock, $key);
    }
}
