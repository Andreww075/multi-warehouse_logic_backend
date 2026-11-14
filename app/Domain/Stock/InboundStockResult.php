<?php

declare(strict_types=1);

namespace App\Domain\Stock;

/**
 * Resultado de una recepción inbound (F2 / T-26).
 */
final readonly class InboundStockResult
{
    public function __construct(
        public string $movementId,
        public int $quantityBefore,
        public int $quantityAfter,
    ) {}
}
