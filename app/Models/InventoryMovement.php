<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Auditoría append-only (Spec F1 §3, RF-12; Constitución Art. I.3).
 *
 * Refuerzo a nivel de aplicación de la garantía que ya vive en la base de datos
 * (trigger de la migración 2026_11_14_000005): los eventos `updating` y `deleting`
 * fallan siempre. No existen mutadores que permitan editar el histórico.
 *
 * @property int $quantity_before Stock físico (on_hand) antes del movimiento.
 * @property int $quantity_after Stock físico (on_hand) después del movimiento.
 * @property Carbon $created_at Momento de creación persistido por la BD (RF-10).
 */
#[Fillable(['warehouse_id', 'product_id', 'type', 'quantity', 'quantity_before', 'quantity_after', 'idempotency_key', 'correlation_id'])]
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory, HasUuids;

    /**
     * Auditoría append-only: solo created_at (generado por la BD con useCurrent),
     * sin updated_at (Spec F1 §3).
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            // RF-10: el replay expone created_at de la primera ejecución.
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('inventory_movements es append-only: UPDATE prohibido (Art. I.3 de la Constitución)');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('inventory_movements es append-only: DELETE prohibido (Art. I.3 de la Constitución)');
        });
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
