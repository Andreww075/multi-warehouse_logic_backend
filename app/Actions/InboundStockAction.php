<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\Exceptions\ProductInactiveException;
use App\Domain\Exceptions\ProductNotFoundException;
use App\Domain\Exceptions\WarehouseInactiveException;
use App\Domain\Exceptions\WarehouseNotFoundException;
use App\Domain\Stock\InboundStockCommand;
use App\Domain\Stock\InboundStockResult;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F2 (T-26) — Orquestador de la recepción inbound de stock.
 *
 * La creación de la fila `inventories` es responsabilidad del inbound (Plan
 * P-07, RF-07a); el deducir nunca la crea. Un inbound: valida referencias y
 * estado (RF-03/04/05/14), y dentro de una transacción registra el movimiento
 * `inbound` y crea/incrementa el inventario (`on_hand += quantity`).
 */
class InboundStockAction
{
    public function execute(InboundStockCommand $command): InboundStockResult
    {
        $product = Product::query()->find($command->productId);

        if ($product === null) {
            throw new ProductNotFoundException(sprintf('producto %s no existe en el catálogo', $command->productId))
                ->withContext(['product_id' => $command->productId, 'warehouse_id' => $command->warehouseId]);
        }

        if (! $product->is_active) {
            throw new ProductInactiveException(sprintf('producto %s está inactivo', $command->productId))
                ->withContext(['product_id' => $command->productId, 'warehouse_id' => $command->warehouseId]);
        }

        $warehouse = Warehouse::query()->find($command->warehouseId);

        if ($warehouse === null) {
            throw new WarehouseNotFoundException(sprintf('almacén %s no existe', $command->warehouseId))
                ->withContext(['product_id' => $command->productId, 'warehouse_id' => $command->warehouseId]);
        }

        if (! $warehouse->is_active) {
            throw new WarehouseInactiveException(sprintf('almacén %s está inactivo', $command->warehouseId))
                ->withContext(['product_id' => $command->productId, 'warehouse_id' => $command->warehouseId]);
        }

        return DB::transaction(function () use ($command): InboundStockResult {
            $inventory = Inventory::query()
                ->where('warehouse_id', $command->warehouseId)
                ->where('product_id', $command->productId)
                ->lockForUpdate()
                ->first();

            $quantityBefore = $inventory !== null ? $inventory->on_hand : 0;
            $quantityAfter = $quantityBefore + $command->quantity;

            if ($inventory === null) {
                Inventory::query()->create([
                    'warehouse_id' => $command->warehouseId,
                    'product_id' => $command->productId,
                    'on_hand' => $command->quantity,
                    'reserved' => 0,
                ]);
            } else {
                $inventory->increment('on_hand', $command->quantity);
            }

            $movement = InventoryMovement::query()->create([
                'warehouse_id' => $command->warehouseId,
                'product_id' => $command->productId,
                'type' => 'inbound',
                'quantity' => $command->quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'idempotency_key' => (string) Str::uuid(),
                'correlation_id' => $command->correlationId,
            ]);

            return new InboundStockResult(
                movementId: $movement->id,
                quantityBefore: $quantityBefore,
                quantityAfter: $quantityAfter,
            );
        });
    }
}
