<?php

declare(strict_types=1);

namespace App\Domain\Stock;

/**
 * Comando de deducción de stock (RF-02 → M5).
 * Transporte tipado de la intención del checkout; sin arrays crudos (Art. II.2).
 */
final readonly class DeductStockCommand
{
    public function __construct(
        public string $productId,
        public string $warehouseId,
        public int $quantity,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
