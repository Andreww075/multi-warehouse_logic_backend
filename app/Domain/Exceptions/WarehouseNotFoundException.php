<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * RF-04: el warehouse_id no existe.
 * Mapeo HTTP (decidido en el handler): 404 Not Found.
 */
final class WarehouseNotFoundException extends AbstractDomainException {}
