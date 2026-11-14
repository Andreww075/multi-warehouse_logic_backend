<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Stock\DeductStockResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RF-13: contrato de respuesta de deducción exitosa.
 * Estado resultante del inventario + id del movimiento creado.
 * Contrato plano (sin wrapper `data`), simétrico al contrato de error del
 * `DomainExceptionMapper` (Art. IV.1).
 *
 * @mixin DeductStockResult
 */
final class InventoryDeductionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'on_hand' => $this->onHand,
            'reserved' => $this->reserved,
            'available' => $this->available,
            'movement_id' => $this->movementId,
        ];
    }
}
