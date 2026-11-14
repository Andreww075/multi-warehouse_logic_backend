<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\Exceptions\IdempotencyKeyConflictException;
use App\Domain\Exceptions\InsufficientStockException;
use App\Domain\Exceptions\ProductInactiveException;
use App\Domain\Exceptions\ProductNotFoundException;
use App\Domain\Exceptions\WarehouseInactiveException;
use App\Domain\Exceptions\WarehouseNotFoundException;
use App\Domain\Stock\DeductStockCommand;
use App\Domain\Stock\DeductStockOutcome;
use App\Domain\Stock\DeductStockResult;
use App\Domain\Stock\IdempotentReplayResult;
use App\Infrastructure\Stock\StockLockManager;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * M5 · Orquestador de la deducción de stock (T-16, T-17, T-18).
 *
 * Orden estricto (Art. I.1/I.2 de la Constitución; Plan M5):
 *  1. Validaciones de referencia y estado PRE-lock (RF-03/04/05/14): sin lock,
 *     sin transacción, sin tocar stock — un fallo barato no paga locks.
 *  2. Adquisición del lock distribuido (RF-06) con el `sku` del catálogo ya
 *     validado como clave canónica (Art. I.1, resolución A5).
 *  3. Transacción única con relea pesimista `FOR UPDATE` (RF-07, P-04).
 *  4. Idempotencia ya dentro del lock y de la transacción (RF-10/RF-11).
 *  5. Decremento de `on_hand` + movimiento con before/after exactos (RF-19, Art. I.2).
 *  6. Release en `finally` con verificación de propiedad (RF-16).
 */
class DeductStockAction
{
    public function __construct(
        private readonly StockLockManager $lockManager,
    ) {}

    /**
     * @throws ProductNotFoundException|ProductInactiveException
     * @throws WarehouseNotFoundException|WarehouseInactiveException
     * @throws InsufficientStockException
     * @throws IdempotencyKeyConflictException
     */
    public function execute(DeductStockCommand $command): DeductStockOutcome
    {
        // (1) Fases pre-lock: referencias (404) y estado (409), en ese orden.
        $product = Product::query()->find($command->productId);

        if ($product === null) {
            throw (new ProductNotFoundException(sprintf(
                'producto %s no existe en el catálogo',
                $command->productId,
            )))->withContext(['product_id' => $command->productId, 'warehouse_id' => $command->warehouseId]);
        }

        $warehouse = Warehouse::query()->find($command->warehouseId);

        if ($warehouse === null) {
            throw (new WarehouseNotFoundException(sprintf(
                'almacén %s no existe',
                $command->warehouseId,
            )))->withContext(['product_id' => $command->productId, 'warehouse_id' => $command->warehouseId]);
        }

        if (! $warehouse->is_active) {
            throw (new WarehouseInactiveException(sprintf(
                'almacén %s está inactivo',
                $command->warehouseId,
            )))->withContext(['product_id' => $command->productId, 'warehouse_id' => $command->warehouseId]);
        }

        if (! $product->is_active) {
            throw (new ProductInactiveException(sprintf(
                'producto %s está inactivo',
                $command->productId,
            )))->withContext(['product_id' => $command->productId, 'warehouse_id' => $command->warehouseId]);
        }

        // (2) Lock distribuido con el sku del catálogo ya validado (RF-06, A5).
        $lock = $this->lockManager->acquire($product->sku, $command->warehouseId);

        try {
            return $this->deductWithinTransaction($command);
        } catch (UniqueConstraintViolationException $race) {
            // Carrera por la UNIQUE(idempotency_key) entre PARES DISTINTOS
            // (claves de lock diferentes, RF-17): quien llega segundo después
            // del commit del otro resuelve por re-lectura (RF-10 o RF-17).
            return $this->resolveIdempotencyRace($command, $race);
        } finally {
            // (6) No-op seguro si el lock expiró o cambió de dueño (RF-16).
            $lock->release();
        }
    }

    /**
     * Fases 3-5 dentro de UNA transacción (RF-07): relea pesimista, validación
     * de disponibilidad, decremento e inserción del movimiento.
     */
    private function deductWithinTransaction(DeductStockCommand $command): DeductStockOutcome
    {
        return DB::transaction(function () use ($command): DeductStockOutcome {
            // (4) Idempotencia bajo lock: reintentos de red nunca duplican ventas.
            $existing = InventoryMovement::query()
                ->where('idempotency_key', $command->idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $this->replayOrConflict($command, $existing);
            }

            // (3a) Relectura con bloqueo de fila: segunda línea de defensa (P-04).
            $inventory = Inventory::query()
                ->where('warehouse_id', $command->warehouseId)
                ->where('product_id', $command->productId)
                ->lockForUpdate()
                ->first();

            // RF-07a / P-07: fila inexistente = stock disponible 0; deducir nunca
            // crea estado (la creación es responsabilidad del inbound).
            if ($inventory === null) {
                throw (new InsufficientStockException(sprintf(
                    'sin fila de inventario para producto %s en almacén %s',
                    $command->productId,
                    $command->warehouseId,
                )))->withContext([
                    'product_id' => $command->productId,
                    'warehouse_id' => $command->warehouseId,
                    'available' => 0,
                    'quantity' => $command->quantity,
                ]);
            }

            $available = $inventory->available();

            // (3b) RF-08: available = on_hand - reserved ≥ quantity.
            if ($available < $command->quantity) {
                throw (new InsufficientStockException(sprintf(
                    'stock insuficiente: disponible %d, solicitado %d',
                    $available,
                    $command->quantity,
                )))->withContext([
                    'product_id' => $command->productId,
                    'warehouse_id' => $command->warehouseId,
                    'available' => $available,
                    'quantity' => $command->quantity,
                ]);
            }

            // (5a) Decremento atómico de on_hand; la invariante sobrevive a cualquier
            // bug de aplicación gracias a los CHECK de BD (RF-12, P-08).
            // Se descuenta vía builder (atómico) y el after se calcula localmente.
            $quantityBefore = $inventory->on_hand;
            $inventory->decrement('on_hand', $command->quantity);
            $quantityAfter = $quantityBefore - $command->quantity;

            // (5b) Movimiento auditado con before/after exactos (RF-12, Art. I.2).
            $movement = InventoryMovement::query()->create([
                'warehouse_id' => $command->warehouseId,
                'product_id' => $command->productId,
                'type' => 'outbound',
                'quantity' => $command->quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'idempotency_key' => $command->idempotencyKey,
                'correlation_id' => $command->correlationId,
            ]);

            return new DeductStockResult(
                productId: $command->productId,
                warehouseId: $command->warehouseId,
                onHand: $quantityAfter,
                reserved: $inventory->reserved,
                available: $quantityAfter - $inventory->reserved,
                movementId: $movement->id,
            );
        });
    }

    /**
     * RF-10 (P-06): replay con los datos persistidos de la primera ejecución,
     * sin re-descontar; RF-17 (C3): clave global con par distinto = 409.
     */
    private function replayOrConflict(
        DeductStockCommand $command,
        InventoryMovement $existing,
    ): DeductStockOutcome {
        $samePair = $existing->warehouse_id === $command->warehouseId
            && $existing->product_id === $command->productId;

        if (! $samePair) {
            throw (new IdempotencyKeyConflictException(sprintf(
                'idempotency_key %s ya consumida por movimiento %s con otro par (almacén, producto)',
                $command->idempotencyKey,
                $existing->id,
            )))->withContext([
                'product_id' => $command->productId,
                'warehouse_id' => $command->warehouseId,
                'movement_id' => $existing->id,
                'original_product_id' => $existing->product_id,
                'original_warehouse_id' => $existing->warehouse_id,
            ]);
        }

        return new IdempotentReplayResult(
            movementId: $existing->id,
            quantity: $existing->quantity,
            quantityBefore: $existing->quantity_before,
            quantityAfter: $existing->quantity_after,
            createdAt: $existing->created_at->toIso8601String(),
        );
    }

    /**
     * Resolución de la carrera de idempotencia: re-lectura del movimiento
     * persistido por el proceso ganador; sin deducción de nada.
     */
    private function resolveIdempotencyRace(
        DeductStockCommand $command,
        UniqueConstraintViolationException $race,
    ): DeductStockOutcome {
        $existing = InventoryMovement::query()
            ->where('idempotency_key', $command->idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->replayOrConflict($command, $existing);
        }

        // Telemetría honesta: no debería ocurrir (la serialización por lock
        // cubre pares iguales). Se preserva la excepción original.
        throw $race;
    }
}
