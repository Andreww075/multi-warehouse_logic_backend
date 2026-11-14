<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Stock\IdempotentReplayResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RF-10: contrato de respuesta idempotente (replay).
 * Datos PERSISTIDOS de la primera ejecución — el estado en el momento de esa
 * venta, no el vigente. Contrato plano, simétrico con el resto (Art. IV.1).
 *
 * @mixin IdempotentReplayResult
 */
final class InventoryMovementReplayResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'movement_id' => $this->movementId,
            'quantity' => $this->quantity,
            'quantity_before' => $this->quantityBefore,
            'quantity_after' => $this->quantityAfter,
            'created_at' => $this->createdAt,
        ];
    }
}
