<?php

declare(strict_types=1);

namespace App\Domain\Stock;

/**
 * Resultado de un replay idempotente (RF-10 / Plan P-06).
 *
 * Transporta los datos PERSISTIDOS de la primera ejecución — el estado en el
 * momento de esa venta —, no el estado vigente del inventario. Determinista:
 * reconstruible desde el único movimiento que existe.
 */
final readonly class IdempotentReplayResult implements DeductStockOutcome
{
    public function __construct(
        public string $movementId,
        public int $quantity,
        public int $quantityBefore,
        public int $quantityAfter,
        public string $createdAt,
    ) {}
}
