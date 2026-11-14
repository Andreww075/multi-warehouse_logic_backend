<?php

declare(strict_types=1);

namespace App\Domain\Stock;

/**
 * Resultado de una deducción exitosa (RF-19).
 * Estado resultante del inventario + movimiento creado.
 */
final readonly class DeductStockResult implements DeductStockOutcome
{
    public function __construct(
        public string $productId,
        public string $warehouseId,
        public int $onHand,
        public int $reserved,
        public int $available,
        public string $movementId,
    ) {}
}
