<?php

declare(strict_types=1);

namespace App\Domain\Stock;

final readonly class InboundStockCommand
{
    public function __construct(
        public string $productId,
        public string $warehouseId,
        public int $quantity,
        public string $correlationId,
    ) {}
}
